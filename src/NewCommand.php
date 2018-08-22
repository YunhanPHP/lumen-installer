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
     * Configure the command options.
     * @return void
     */
    protected function configure()
    {
        parent::configure();
        $this->addOption('inner');
    }

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

        $projectName = $input->getArgument('name');

        $this->verifyApplicationDoesntExist(
            $directory = getcwd() . '/' . $projectName
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


        // 执行项目框架所需
        $composer = $this->findComposer();

        $commands = [
            // 安装 lumen，不用 lumen 安装方法是因为其中的下载包有时会无法下载
            $composer . ' create-project laravel/lumen src --prefer-dist',

            // 进入对应目录
            'cd src',

            // 移动文件
            'php -r "@rename(\'' . $directory . '/tmp/.phan\', \'' . $directory . '/src/.phan\');"',
            'php -r "@rmdir(\'' . $directory . '/tmp\');"',
        ];

        $isInner = $input->getOption('inner');

        // 生产环境集成
        if ($isInner) {
            // 公司内部
            $lumenRequireDir = $directory . '/src/vendor/yunhanphp/lumen-require';
            $commands = array_merge($commands, [
                $composer . ' config repositories.lumen-require git ssh://git@code.aliyun.com/jqb-php/lumen-require.git',
                $composer . ' require yunhanphp/lumen-require dev-master',

                // 覆盖bootstrap
                'php -r "copy(\'' . $lumenRequireDir . '/app/bootstrap/app.php\', \'' . $directory . '/src/bootstrap/app.php\');"',
                'php -r "copy(\'' . $lumenRequireDir . '/app/env.example.php\', \'' . $directory . '/src/env.example.php\');"',
                'php -r "copy(\'' . $directory . '/src/env.example.php\', \'' . $directory . '/src/env.php\');"',
                'php -r "@unlink(\'' . $directory . '/src/.env\');"',
                'php -r "@unlink(\'' . $directory . '/src/.env.example\');"',
            ]);
        } else {
            $commands = array_merge($commands, [
                $composer . ' require albertcht/lumen-helpers'
            ]);
        }

        // 安装开发环境集成
        $commands = array_merge($commands, [
            $composer . ' config repositories.lumen-app-installer git https://github.com/YunhanPHP/lumen-require-dev.git',
            $composer . ' config repositories.lumen-dev-db-doc git https://github.com/YunhanPHP/lumen-dev-db-doc.git',
            $composer . ' config repositories.lumen-dev-yaml-swagger git https://github.com/YunhanPHP/lumen-dev-yaml-swagger.git',
            $composer . ' require --dev yunhanphp/lumen-require-dev dev-master',
        ]);

        if ($isInner) {
            $commands = array_merge($commands, [
                'php artisan ide-helper:generate',
                'php artisan ide-helper:meta'
            ]);
        }

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value . ' --no-ansi';
            }, $commands);
        }

        $process = new Process(implode(' && ', $commands), $directory, null, null, null);

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
