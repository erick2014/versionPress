<?php

namespace VersionPress\Api;

require_once ABSPATH . 'wp-admin/includes/file.php';

use Nette\Utils\Strings;
use VersionPress\Api\BundledWpApi\WP_REST_Request;
use VersionPress\Api\BundledWpApi\WP_REST_Response;
use VersionPress\Api\BundledWpApi\WP_REST_Server;
use VersionPress\ChangeInfos\ChangeInfoEnvelope;
use VersionPress\ChangeInfos\ChangeInfoMatcher;
use VersionPress\ChangeInfos\EntityChangeInfo;
use VersionPress\ChangeInfos\PluginChangeInfo;
use VersionPress\ChangeInfos\RevertChangeInfo;
use VersionPress\ChangeInfos\ThemeChangeInfo;
use VersionPress\ChangeInfos\TrackedChangeInfo;
use VersionPress\ChangeInfos\WordPressUpdateChangeInfo;
use VersionPress\Configuration\VersionPressConfig;
use VersionPress\DI\VersionPressServices;
use VersionPress\Git\Commit;
use VersionPress\Git\CommitMessage;
use VersionPress\Git\GitLogPaginator;
use VersionPress\Git\GitRepository;
use VersionPress\Git\Reverter;
use VersionPress\Git\RevertStatus;
use VersionPress\Initialization\VersionPressOptions;
use VersionPress\Synchronizers\SynchronizationProcess;
use VersionPress\Utils\ArrayUtils;
use VersionPress\Utils\BugReporter;

class VersionPressApi {

    private $gitRepository;
    

    private $reverter;
    

    private $vpConfig;
    

    private $synchronizationProcess;

    public function __construct(GitRepository $gitRepository, Reverter $reverter, VersionPressConfig $vpConfig, SynchronizationProcess $synchronizationProcess) {
        $this->gitRepository = $gitRepository;
        $this->reverter = $reverter;
        $this->vpConfig = $vpConfig;
        $this->synchronizationProcess = $synchronizationProcess;
    }

    public function register_routes() {
        $namespace = 'versionpress';

        register_vp_rest_route($namespace, '/commits', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'getCommits'),
            'args' => array(
                'page' => array(
                    'default' => '0'
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/undo', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'undoCommit'),
            'args' => array(
                'commit' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/rollback', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rollbackToCommit'),
            'args' => array(
                'commit' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/can-revert', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'canRevert'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/diff', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'getDiff'),
            'args' => array(
                'commit' => array(
                    'default' => null
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/submit-bug', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submitBug'),
            'args' => array(
                'email' => array(
                    'required' => true
                ),
                'description' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/display-welcome-panel', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'displayWelcomePanel'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/hide-welcome-panel', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'hideWelcomePanel'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/should-update', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'shouldUpdate'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/git-status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'getGitStatus'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/commit', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'commit'),
            'args' => array(
                'commit-message' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/discard-changes', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'discardChanges'),
            'permission_callback' => array($this, 'checkPermissions')
        ));
    }

    public function getCommits(WP_REST_Request $request) {
        $gitLogPaginator = new GitLogPaginator($this->gitRepository);
        $gitLogPaginator->setCommitsPerPage(25);

        $page = intval($request['page']);
        $commits = $gitLogPaginator->getPage($page);

        if (empty($commits)) {
            return new \WP_Error('notice', 'No more commits to show.', array('status' => 403));
        }

        $preActivationHash = trim(file_get_contents(VERSIONPRESS_ACTIVATION_FILE));
        if (empty($preActivationHash)) {
            $initialCommitHash = $this->gitRepository->getInitialCommit()->getHash();
        } else {
            $initialCommitHash = $this->gitRepository->getChildCommit($preActivationHash);
        }

        $isChildOfInitialCommit = $this->gitRepository->wasCreatedAfter($commits[0]->getHash(), $initialCommitHash);
        $isFirstCommit = $page === 0;

        $result = array();
        foreach ($commits as $commit) {
            $isChildOfInitialCommit = $isChildOfInitialCommit && ($commit->getHash() !== $initialCommitHash);
            $canUndoCommit = $isChildOfInitialCommit && !$commit->isMerge();
            $canRollbackToThisCommit = !$isFirstCommit && ($isChildOfInitialCommit || $commit->getHash() === $initialCommitHash);
            $changeInfo = ChangeInfoMatcher::buildChangeInfo($commit->getMessage());
            $isEnabled = $isChildOfInitialCommit || $canRollbackToThisCommit || $commit->getHash() === $initialCommitHash;

            $fileChanges = $this->getFileChanges($commit);
            $changeInfoList = $changeInfo instanceof ChangeInfoEnvelope ? $changeInfo->getChangeInfoList() : array();

            $result[] = array(
                "hash" => $commit->getHash(),
                "date" => $commit->getDate()->format('c'),
                "message" => $changeInfo->getChangeDescription(),
                "canUndo" => $canUndoCommit,
                "canRollback" => $canRollbackToThisCommit,
                "isEnabled" => $isEnabled,
                "isInitial" => $commit->getHash() === $initialCommitHash,
                "isMerge" => $commit->isMerge(),
                "changes" => array_merge($this->convertChangeInfoList($changeInfoList), $fileChanges),
            );
            $isFirstCommit = false;
        }
        return new WP_REST_Response(array(
            'pages' => $gitLogPaginator->getPrettySteps($page),
            'commits' => $result
        ));
    }

    public function undoCommit(WP_REST_Request $request) {
        return $this->revertCommit('undo', $request['commit']);
    }

    public function rollbackToCommit(WP_REST_Request $request) {
        return $this->revertCommit('rollback', $request['commit']);
    }

    public function canRevert() {
        return new WP_REST_Response($this->reverter->canRevert());
    }

    public function revertCommit($reverterMethod, $commit) {
        vp_enable_maintenance();
        $revertStatus = call_user_func(array($this->reverter, $reverterMethod), $commit);
        vp_disable_maintenance();

        if ($revertStatus !== RevertStatus::OK) {
            return $this->getError($revertStatus);
        }
        return new WP_REST_Response(true);
    }

    public function getDiff(WP_REST_Request $request) {
        $hash = $request['commit'];
        $diff = $this->gitRepository->getDiff($hash);

        if (strlen($diff) > 50 * 1024) { 
            return new \WP_Error(
                'error',
                'The diff is too large to show here. Please use some Git client. Thank you.',
                array('status' => 403));
        }

        return new WP_REST_Response(array('diff' => $diff));
    }

    public function submitBug(WP_REST_Request $request) {
        $email = $request['email'];
        $description = $request['description'];

        $bugReporter = new BugReporter('http://versionpress.net/report-problem');
        $reportedSuccessfully = $bugReporter->reportBug($email, $description);

        if ($reportedSuccessfully) {
            return new WP_REST_Response(true);
        } else {
            return new \WP_Error(
                'error',
                'There was a problem with sending bug report. Please try it again. Thank you.',
                array('status' => 403)
            );
        }
    }

    public function displayWelcomePanel() {
        $showWelcomePanel = get_user_meta(get_current_user_id(), VersionPressOptions::USER_META_SHOW_WELCOME_PANEL, true);
        return new WP_REST_Response($showWelcomePanel === "");
    }

    public function hideWelcomePanel() {
        update_user_meta(get_current_user_id(), VersionPressOptions::USER_META_SHOW_WELCOME_PANEL, "0");
        return new WP_REST_Response(null, 204);
    }

    public function shouldUpdate(WP_REST_Request $request) {
        global $versionPressContainer;
        

        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);

        $latestCommit = $request['latestCommit'];

        return new WP_REST_Response(array(
            "update" => $repository->wasCreatedAfter("HEAD", $latestCommit),
            "cleanWorkingDirectory" => $repository->isCleanWorkingDirectory()
        ));
    }

    public function getGitStatus() {
        global $versionPressContainer;
        

        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);

        return new WP_REST_Response($repository->getStatus(true));
    }

    public function commit(WP_REST_Request $request) {
        $currentUser = wp_get_current_user();
        if ($currentUser->ID === 0) {
            return new \WP_Error(
                'error',
                'You don\'t have permission to do this.',
                array('status' => 403));
        }

        $authorName = $currentUser->display_name;
        

        $authorEmail = $currentUser->user_email;

        $this->gitRepository->stageAll();

        $status = $this->gitRepository->getStatus(true);
        if (ArrayUtils::any($status, function ($fileStatus) {
            return Strings::contains($fileStatus[1], 'vpdb');
        })) {
            $this->updateDatabase($status);
        }

        $this->gitRepository->commit($request['commit-message'], $authorName, $authorEmail);
        return new WP_REST_Response(true);
    }

    private function updateDatabase($status) {
        $diff = $this->gitRepository->getDiff();
        $vpidRegex = "/([\\da-f]{32})/i";
        $optionRegex = "/.*vpdb[\\/\\\\]options[\\/\\\\].+[\\/\\\\](.+)\\.ini/i";

        preg_match_all($vpidRegex, $diff, $vpidMatches);
        preg_match_all($optionRegex, $diff, $optionNameMatches);

        $entitiesToSynchronize = array_unique(array_merge($vpidMatches[1], $optionNameMatches[1]));
        $this->synchronizationProcess->synchronize($entitiesToSynchronize);
    }

    public function discardChanges() {
        global $versionPressContainer;
        

        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);

        $result = $repository->clearWorkingDirectory();

        return new WP_REST_Response($result);
    }

    public function getError($status) {
        $errors = array(
            RevertStatus::MERGE_CONFLICT => array(
                'class' => 'error',
                'message' => 'Error: Overwritten changes can not be reverted.',
                'status' => 403
            ),
            RevertStatus::NOTHING_TO_COMMIT => array(
                'class' => 'updated',
                'message' => 'There was nothing to commit. Current state is the same as the one you want rollback to.',
                'status' => 403
            ),
            RevertStatus::VIOLATED_REFERENTIAL_INTEGRITY => array(
                'class' => 'error',
                'message' => 'Error: Objects with missing references cannot be restored. For example we cannot restore comment where the related post was deleted.',
                'status' => 403
            ),
            RevertStatus::REVERTING_MERGE_COMMIT => array(
                'class' => 'error',
                'message' => 'Error: It is not possible to undo merge commit.',
                'status' => 403
            ),
        );

        $error = $errors[$status];
        return new \WP_Error(
            $error['class'],
            $error['message'],
            array('status' => $error['status'])
        );
    }

    public function checkPermissions(WP_REST_Request $request) {
        return !$this->vpConfig->mergedConfig['requireApiAuth'] || current_user_can('manage_options')
            ? true
            : new \WP_Error(
                'error',
                'You don\'t have permission to do this.',
                array('status' => 403)
            );
    }

    private function convertChangeInfoList($getChangeInfoList) {
        return array_map(array($this, 'convertChangeInfo'), $getChangeInfoList);
    }

    private function convertChangeInfo($changeInfo) {
        $change = array();

        if ($changeInfo instanceof TrackedChangeInfo) {
            $change['type'] = $changeInfo->getEntityName();
            $change['action'] = $changeInfo->getAction();
            $change['tags'] = $changeInfo->getCustomTags();
        }

        if ($changeInfo instanceof EntityChangeInfo) {
            $change['name'] = $changeInfo->getEntityId();
        }

        if ($changeInfo instanceof PluginChangeInfo) {
            $pluginTags = $changeInfo->getCustomTags();
            $pluginName = $pluginTags[PluginChangeInfo::PLUGIN_NAME_TAG];
            $change['name'] = $pluginName;
        }

        if ($changeInfo instanceof ThemeChangeInfo) {
            $themeTags = $changeInfo->getCustomTags();
            $themeName = $themeTags[ThemeChangeInfo::THEME_NAME_TAG];
            $change['name'] = $themeName;
        }

        if ($changeInfo instanceof WordPressUpdateChangeInfo) {
            $change['name'] = $changeInfo->getNewVersion();
        }

        if ($changeInfo instanceof RevertChangeInfo) {
            $commit = $this->gitRepository->getCommit($changeInfo->getCommitHash());
            $change['tags']['VP-Commit-Details'] = array(
                'message' => $commit->getMessage()->getSubject(),
                'date' => $commit->getDate()->format(\DateTime::ISO8601)
            );
        }

        return $change;
    }

    private function getFileChanges(Commit $commit) {
        $changedFiles = $commit->getChangedFiles();

        $changedFiles = array_filter($changedFiles, function ($changedFile) {
            $path = str_replace('\\', '/', ABSPATH . $changedFile['path']);
            $vpdbPath = str_replace('\\', '/', VERSIONPRESS_MIRRORING_DIR);

            return !Strings::startsWith($path, $vpdbPath);
        });

        $fileChanges = array_map(function ($changedFile) {
            $status = $changedFile['status'];
            $filename = $changedFile['path'];

            return array(
                'type' => 'file',
                'action' => $status === 'A' ? 'add' : ($status === 'M' ? 'modify' : 'delete'),
                'name' => $filename,
            );
        }, $changedFiles);

        return $fileChanges;
    }
}
