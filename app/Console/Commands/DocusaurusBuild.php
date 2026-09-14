<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:docusaurus-build')]
#[Description('Build project docs.')]
class DocusaurusBuild extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Building Docusaurus docs...');
        $exitCode = 0;

        // Run the Docusaurus build command
        $process = proc_open(
            'cd documentation && npx docusaurus build',
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );

        if (is_resource($process)) {
            // Output the command's stdout and stderr
            while ($line = fgets($pipes[1])) {
                $this->line($line);
            }
            while ($line = fgets($pipes[2])) {
                $this->error($line);
                $exitCode = 1; // Set exit code to 1 if there is an error
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        } else {
            $this->error('Failed to start the Docusaurus build process.');
            $exitCode = 1;
        }

        //        Move build to public directory for hosting.
        if ($exitCode === 0) {
            $this->info('Moving Docusaurus build to public directory...');
            $moveProcess = proc_open(
                'rm -rf public/docusaurus && mv documentation/build public/docusaurus',
                [
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'], // stderr
                ],
                $movePipes
            );

            if (is_resource($moveProcess)) {
                while ($line = fgets($movePipes[1])) {
                    $this->line($line);
                }
                while ($line = fgets($movePipes[2])) {
                    $this->error($line);
                    $exitCode = 1; // Set exit code to 1 if there is an error
                }

                fclose($movePipes[1]);
                fclose($movePipes[2]);

                $exitCode = proc_close($moveProcess);
            } else {
                $this->error('Failed to move the Docusaurus build to the public directory.');
                $exitCode = 1;
            }
        }

        return $exitCode;
    }
}
