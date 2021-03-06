<?php
namespace VersionPress\ChangeInfos;

use ChangeInfos\Sorting\SortingStrategy;
use Nette\Utils\Strings;
use VersionPress\Git\CommitMessage;
use VersionPress\Utils\ArrayUtils;
use VersionPress\VersionPress;

class ChangeInfoEnvelope implements ChangeInfo {

    const VP_VERSION_TAG = "X-VP-Version";

    private $changeInfoList;

    private $version;

    private $sortingStrategy;

    private $bulkChangeInfoClasses = array(
        "comment" => 'VersionPress\ChangeInfos\BulkCommentChangeInfo',
        "option" => 'VersionPress\ChangeInfos\BulkOptionChangeInfo',
        "plugin" => 'VersionPress\ChangeInfos\BulkPluginChangeInfo',
        "post" => 'VersionPress\ChangeInfos\BulkPostChangeInfo',
        "postmeta" => 'VersionPress\ChangeInfos\BulkPostMetaChangeInfo',
        "term" => 'VersionPress\ChangeInfos\BulkTermChangeInfo',
        "termmeta" => 'VersionPress\ChangeInfos\BulkTermMetaChangeInfo',
        "theme" => 'VersionPress\ChangeInfos\BulkThemeChangeInfo',
        "translation" => 'VersionPress\ChangeInfos\BulkTranslationChangeInfo',
        "user" => 'VersionPress\ChangeInfos\BulkUserChangeInfo',
        "usermeta" => 'VersionPress\ChangeInfos\BulkUserMetaChangeInfo',
    );

    public function __construct($changeInfoList, $version = null, $sortingStrategy = null) {
        $this->changeInfoList = $changeInfoList;
        $this->version = $version === null ? VersionPress::getVersion() : $version;
        $this->sortingStrategy = $sortingStrategy === null ? new SortingStrategy() : $sortingStrategy;
    }

    public function getCommitMessage() {
        $subject = $this->getChangeDescription();

        $bodies = array();
        foreach ($this->getSortedChangeInfoList() as $changeInfo) {
            $bodies[] = $changeInfo->getCommitMessage()->getBody();
        }

        $body = join("\n\n", $bodies);
        $body .= sprintf("\n\n%s: %s", self::VP_VERSION_TAG, $this->version);

        return new CommitMessage($subject, $body);
    }

    public function getChangeDescription() {
        $changeList = $this->getReorganizedInfoList();
        $firstChangeDescription = $changeList[0]->getChangeDescription();
        return $firstChangeDescription;
    }

    public static function buildFromCommitMessage(CommitMessage $commitMessage) {
        $fullBody = $commitMessage->getBody();
        $splittedBodies = explode("\n\n", $fullBody);
        $lastBody = $splittedBodies[count($splittedBodies) - 1];
        $changeInfoList = array();
        $version = null;

        if (self::containsVersion($lastBody)) {
            $version = self::extractVersion($lastBody);
            array_pop($splittedBodies);
        }

        foreach ($splittedBodies as $body) {
            $partialCommitMessage = new CommitMessage("", $body);
            

            $matchingChangeInfoType = ChangeInfoMatcher::findMatchingChangeInfo($partialCommitMessage);
            $changeInfoList[] = $matchingChangeInfoType::buildFromCommitMessage($partialCommitMessage);
        }

        return new self($changeInfoList, $version);
    }

    public function getChangeInfoList() {
        return $this->changeInfoList;
    }

    public function getReorganizedInfoList() {
        return $this->sortingStrategy->sort($this->groupBulkActions($this->changeInfoList));
    }

    private function getSortedChangeInfoList() {
        return $this->sortingStrategy->sort($this->changeInfoList);
    }

    private static function containsVersion($lastBody) {
        return Strings::startsWith($lastBody, self::VP_VERSION_TAG);
    }

    private static function extractVersion($lastBody) {
        $tmpMessage = new CommitMessage("", $lastBody);
        $version = $tmpMessage->getVersionPressTag(self::VP_VERSION_TAG);
        return $version;
    }

    private function groupBulkActions($changeInfoList) {
        $bulkChangeInfoClasses = $this->bulkChangeInfoClasses;

        $groupedChangeInfos = ArrayUtils::mapreduce($changeInfoList, function (TrackedChangeInfo $item, $mapEmit) {
            $key = "{$item->getEntityName()}/{$item->getAction()}";
            $mapEmit($key, $item);
        }, function ($key, $items, $reduceEmit) use ($bulkChangeInfoClasses) {
            

            if (count($items) > 1) {
                $entityName = $items[0]->getEntityName();
                if (isset($bulkChangeInfoClasses[$entityName])) {
                    $reduceEmit(new $bulkChangeInfoClasses[$entityName]($items));
                } else {
                    $reduceEmit($items);
                }
            } else {
                $reduceEmit($items[0]);
            }
        });

        $changeInfos = array();
        foreach ($groupedChangeInfos as $changeInfoGroup) {
            if (is_array($changeInfoGroup)) {
                foreach ($changeInfoGroup as $changeInfo) {
                    $changeInfos[] = $changeInfo;
                }
            } else {
                $changeInfos[] = $changeInfoGroup;
            }
        }

        return $changeInfos;
    }
}

