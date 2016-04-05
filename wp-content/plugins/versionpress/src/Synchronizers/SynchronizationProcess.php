<?php

namespace VersionPress\Synchronizers;

class SynchronizationProcess {

    private $synchronizerFactory;

    function __construct(SynchronizerFactory $synchronizerFactory) {
        $this->synchronizerFactory = $synchronizerFactory;
    }

    function synchronize(array $vpidsToSynchronize) {
        

        @set_time_limit(0); 

        $synchronizerFactory = $this->synchronizerFactory;
        $allSynchronizers = $synchronizerFactory->getSynchronizationSequence();

        $synchronizationTasks = array_map(function ($synchronizerName) use ($vpidsToSynchronize, $synchronizerFactory) {
            $synchronizer = $synchronizerFactory->createSynchronizer($synchronizerName);
            return array('synchronizer' => $synchronizer, 'task' => Synchronizer::SYNCHRONIZE_EVERYTHING, 'entities' => $vpidsToSynchronize['entities']);
        }, $allSynchronizers);

        $this->runSynchronizationTasks($synchronizationTasks);
    }

    public function synchronizeAll() {
        $synchronizerFactory = $this->synchronizerFactory;
        $synchronizationTasks = array_map(function ($synchronizerName) use ($synchronizerFactory) {
            $synchronizer = $synchronizerFactory->createSynchronizer($synchronizerName);
            return array ('synchronizer' => $synchronizer, 'task' => Synchronizer::SYNCHRONIZE_EVERYTHING, 'entities' => null);
        }, $synchronizerFactory->getSynchronizationSequence());

        $this->runSynchronizationTasks($synchronizationTasks);
    }

    private function runSynchronizationTasks(array $synchronizationTasks) {
        while (count($synchronizationTasks) > 0) {
            $task = array_shift($synchronizationTasks);
            

            $synchronizer = $task['synchronizer'];
            $remainingTasks = $synchronizer->synchronize($task['task'], $task['entities']);

            foreach ($remainingTasks as $remainingTask) {
                $synchronizationTasks[] = array('synchronizer' => $synchronizer, 'task' => $remainingTask, 'entities' => $task['entities']);
            }
        }
    }
}