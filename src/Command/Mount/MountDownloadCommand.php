<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MountDownloadCommand extends MountCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:download')
            ->setDescription('Download files from a mount, using rsync')
            ->addOption('mount', 'm', InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The directory to which files will be downloaded')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Whether to delete extraneous files in the target directory')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $appName = $this->selectApp($input);
        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($appName);

        $appConfig = $this->getAppConfig($sshUrl, (bool) $input->getOption('refresh'));

        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        if ($input->getOption('mount')) {
            $mountPath = $this->validateMountPath($input->getOption('mount'), $appConfig['mounts']);
        } elseif ($input->isInteractive()) {
            $mountPath = $questionHelper->choose(
                $this->getMountsAsOptions($appConfig['mounts']),
                'Enter a number to choose a mount to download from:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $target = null;
        $defaultTarget = null;
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($projectRoot = $this->getProjectRoot()) {
            if ($sharedPath = $this->getSharedPath($mountPath, $appConfig['mounts'])) {
                if (file_exists($projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath)) {
                    $defaultTarget = $projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath;
                }
            }

            $applications = LocalApplication::getApplications($projectRoot, $this->config());
            $appPath = $projectRoot;
            foreach ($applications as $path => $candidateApp) {
                if ($candidateApp->getName() === $appName) {
                    $appPath = $path;
                    break;
                }
            }
            if (is_dir($appPath . '/' . $mountPath)) {
                $defaultTarget = $appPath . '/' . $mountPath;
            }
        }

        if (empty($target) && $input->isInteractive()) {
            $questionText = 'Target directory';
            if ($defaultTarget !== null) {
                $formattedDefaultTarget = $fs->formatPathForDisplay($defaultTarget);
                $questionText .= ' <question>[' . $formattedDefaultTarget . ']</question>';
            }
            $questionText .= ': ';
            $target = $questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultTarget));
        }

        if (empty($target)) {
            $this->stdErr->writeln('The target directory must be specified.');

            return 1;
        }

        $this->validateDirectory($target, true);

        $confirmText = "\nThis will <options=bold>add, replace, and delete</> files in the local directory '<comment>" . $fs->formatPathForDisplay($target) . "</comment>'."
            . "\n\nAre you sure you want to continue?";
        if (!$questionHelper->confirm($confirmText)) {
            return 1;
        }

        $this->runSync($sshUrl, $mountPath, $target, false, (bool) $input->getOption('delete'));

        return 0;
    }
}
