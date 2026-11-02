<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Drivers\AnalysisDrivers;
use Foodics\DbHound\Dump\DumpWriter;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DumpCommand extends Command
{
    use SuspendsCapture;

    public $signature = 'db-hound:dump';

    public $description = 'Write a SENSITIVE JSONL Dump of qualifying Fingerprints with decrypted Samples and raw EXPLAIN output';

    public function handle(QueryRepository $repository, DumpWriter $writer, AnalysisDrivers $drivers, Filesystems $filesystems): int
    {
        // On stderr and at quiet verbosity: a run from cron or a script with --quiet still warns.
        $this->output->getErrorStyle()->writeln(
            '<comment>SENSITIVE: the Dump contains production data (decrypted Samples with real binding values, and raw EXPLAIN output that can hold them). Keep it private, never commit or share it, and delete it once you are done.</comment>',
            OutputInterface::VERBOSITY_QUIET,
        );

        return $this->withoutCapture(fn () => $this->dump($repository, $writer, $drivers, $filesystems));
    }

    private function dump(QueryRepository $repository, DumpWriter $writer, AnalysisDrivers $drivers, Filesystems $filesystems): int
    {
        $diskName = (string) config('db-hound.report.disk', 'local');
        $disk = $filesystems->disk($diskName);

        // Only a file on this host can be kept owner-only.
        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $this->error("The Dump is only written to a local disk, and the report disk [{$diskName}] is not one.");

            return self::FAILURE;
        }

        // A local disk can still be the web's (Laravel's `public` disk): owner-only does not stop the web server serving it.
        $config = (array) (((array) config('filesystems.disks', []))[$diskName] ?? []);

        if (($config['url'] ?? null) !== null || ($config['visibility'] ?? null) === 'public') {
            $this->error("The Dump is never written to a disk served publicly, and the report disk [{$diskName}] has a url or public visibility.");

            return self::FAILURE;
        }

        $directory = trim((string) config('db-hound.report.directory', 'db-hound'), '/');
        $path = ($directory === '' ? '' : $directory.'/').'dump-'.Carbon::now()->format('Y-m-d_His').'-SENSITIVE.jsonl';
        $disk->makeDirectory($directory);
        $file = $disk->path($path);

        try {
            $stream = $this->createOwnerOnly($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $lines = $writer->write($repository->all(), $drivers, $stream);
        } catch (Throwable $e) {
            fclose($stream);
            @unlink($file);

            throw $e;
        }

        fclose($stream);

        $this->info($lines.' Fingerprint(s) written to '.$file.'.');

        return self::SUCCESS;
    }

    /**
     * Create the file owner-only (0600) from the start, whatever the umask,
     * so nobody else can open it before anything is written to it. An
     * existing file is never reused.
     *
     * @return resource
     */
    private function createOwnerOnly(string $file)
    {
        $umask = umask(0077);

        try {
            $stream = @fopen($file, 'x');
        } finally {
            umask($umask);
        }

        if ($stream === false) {
            throw new RuntimeException("Could not create the Dump at {$file}: it already exists or the directory is not writable.");
        }

        // A backstop for a file system that ignores the umask.
        $owned = chmod($file, 0600);
        clearstatcache(true, $file);

        if (! $owned || (fileperms($file) & 0777) !== 0600) {
            fclose($stream);
            @unlink($file);

            throw new RuntimeException("Could not make the Dump at {$file} readable by its owner only (0600); it was removed.");
        }

        return $stream;
    }
}
