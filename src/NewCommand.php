<?php

namespace YunhanPHP\Lumen\Installer\Console;

use GuzzleHttp\Client;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class NewCommand extends \Lumen\Installer\Console\NewCommand
{
    /**
     * Execute the command.
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $this->verifyApplicationDoesntExist(
            $directory = getcwd() . '/' . $input->getArgument('name')
        );

        $output->writeln('<info>Creating application...</info>');

        // 临时目录用来解决多一级目录的问题
        $extractDir = getcwd() . '/lumen_tmp_' . md5(time() . uniqid());

        $this->download($zipFile = $this->makeFilename())
            ->extract($zipFile, $extractDir)
            ->cleanUp($zipFile);

        // 改为指定目录
        @rename($extractDir . '/lumen-app-master', $directory);
        @rmdir($extractDir);

        // 执行 lumen 安装
        $input->setArgument('name', $input->getArgument('name') . '/src');
        (new \Lumen\Installer\Console\NewCommand())->execute($input, $output);

        $output->writeln('<info>Install app deps...</info>');

        // 执行项目框架所需
        $composer = $this->findComposer();

        $commands = [
            // 安装开发环境集成
            $composer . ' config repositories.lumen-app-installer git https://github.com/YunhanPHP/lumen-require-dev.git',
            $composer . ' config repositories.lumen-dev-db-doc git https://github.com/YunhanTech/laravel-db-doc.git',
            $composer . ' config repositories.lumen-dev-yaml-swagger git https://github.com/YunhanTech/swagger-lumen-yaml.git',
            $composer . ' require --dev yunhanphp/lumen-require-dev ^1.0',

            // 复制 env 到本地
            $composer . ' run-script post-root-package-install'
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value . ' --no-ansi';
            }, $commands);
        }

        $process = new Process(implode(' && ', $commands), $directory . '/src', null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<comment>Application ready! Build something amazing.</comment>');
    }

    /**
     * Download the temporary Zip to the given file.
     * @param  string $zipFile
     * @return $this
     */
    protected function download($zipFile)
    {
        $response = (new Client)->get('http://github.com/YunhanPHP/lumen-app/archive/master.zip');

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }
}
