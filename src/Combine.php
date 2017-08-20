<?php

namespace JPry;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Filesystem;

class Combine extends Command
{

    protected function configure()
    {
        $this
            ->setName('combine')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'File name'
            )
            ->addArgument(
                'task',
                InputArgument::OPTIONAL,
                'The name of the column to use as the task name',
                'Task'
            )
            ->addArgument(
                'time',
                InputArgument::OPTIONAL,
                'The name of the column to use as the amount of time.',
                'Hours'
            )
            ->addArgument(
                'notes',
                InputArgument::OPTIONAL,
                'The name of the column to use as the notes data.',
                'Notes'
            )
            ->addOption(
                'no-round',
                'r',
                InputOption::VALUE_NONE,
                'Disable rounding of the times.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $f       = new Filesystem();
        $file    = $input->getArgument('file');
        $task    = $input->getArgument('task');
        $time    = $input->getArgument('time');
        $notes   = $input->getArgument('notes');
        $noround = $input->getOption('no-round');

        if (!$f->exists($file)) {
            throw new FileNotFoundException(null, 0, null, $file);
        }

        $csv = array_map('str_getcsv', file($file));

        // Find the column indexes we need
        $taskIndex = array_search($task, $csv[0]);
        if (false === $taskIndex) {
            throw new \Exception('Unable to find column for task: ' . $task);
        }

        $timeIndex = array_search($time, $csv[0]);
        if (false === $timeIndex) {
            throw new \Exception('Unable to find column for time: ' . $time);
        }

        $notesIndex = array_search($notes, $csv[0]);
        if (false === $notesIndex) {
            throw new \Exception('Unable to find column for notes: ' . $notes);
        }

        // Remove the title row
        unset($csv[0]);

        // Build the rows we care about
        $consolidated = array();
        foreach ($csv as $row) {
            $name  = $row[$notesIndex];
            $type  = $row[$taskIndex];
            $hours = floatval($row[$timeIndex]);

            // Remove an estimated value
            $raw = preg_replace('#(\(\d+\)\s+)#', '', $name);

            // Skip the first item, as it will
            if (!array_key_exists($name, $consolidated)) {
                $consolidated[$name] = array(
                    'type' => $type,
                    'name' => $raw,
                    'time' => $hours,
                );
                continue;
            }

            $consolidated[$name]['time'] += $hours;
        }

        // Round up the consolidated values to the nearest quarter
        if (!$noround) {
            foreach ($consolidated as $name => &$data) {
                $min = floor($data['time']);

                // If it's within .08 hours, close enough to round down.
                if ($min <= $data['time'] && ($min + 0.08) > $data['time']) {
                    $data['time'] = $min;
                    continue;
                }

                // Round to the nearest quarter.
                if (($min + 0.25) >= $data['time']) {
                    $data['time'] = $min + 0.25;
                } elseif (($min + 0.5) >= $data['time']) {
                    $data['time'] = $min + 0.5;
                } elseif (($min + 0.75) >= $data['time']) {
                    $data['time'] = $min + 0.75;
                } else {
                    $data['time'] = ceil($data['time']);
                }
            }
        }

        // Sort the array ascending by type, then descending by time
        array_multisort(
            array_column($consolidated, 'type'), SORT_ASC,
            array_column($consolidated, 'time'), SORT_DESC,
            $consolidated
        );

        // Get the total time
        $total = array_sum(array_column($consolidated, 'time'));

        // Render a table.
        $table = new Table($output);
        $table->setHeaders(array('Task', 'Description', 'Hours'));
        $table->addRows($consolidated);

        // Add total
        $table->addRows(array(
            new TableSeparator(),
            array(new TableCell('Total', array('colspan' => 2)), $total),
        ));

        // Render the table
        $table->setStyle('borderless');
        $table->render();
    }
}
