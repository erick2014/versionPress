<?php
namespace VersionPress\ChangeInfos;

use VersionPress\Git\CommitMessage;

class TermTaxonomyChangeInfo extends EntityChangeInfo {

    const TAXONOMY_TAG = "VP-TermTaxonomy-Taxonomy";
    const TERM_NAME_TAG = "VP-Term-Name";

    private $taxonomy;
    

    private $termName;

    public function __construct($action, $entityId, $taxonomy, $termName) {
        parent::__construct("term_taxonomy", $action, $entityId);
        $this->taxonomy = $taxonomy;
        $this->termName = $termName;
    }

    public function getChangeDescription() {
        $taxonomy = $this->getTaxonomyName();

        switch ($this->getAction()) {
            case "create":
                return "New {$taxonomy} '{$this->termName}'";
            case "delete":
                return "Deleted {$taxonomy} '{$this->termName}'";
        }

        return "Edited {$taxonomy} '{$this->termName}'";
    }

    public static function buildFromCommitMessage(CommitMessage $commitMessage) {
        $tags = $commitMessage->getVersionPressTags();
        $actionTag = $tags[TrackedChangeInfo::ACTION_TAG];
        list(, $action, $entityId) = explode("/", $actionTag, 3);
        $termName = $tags[self::TERM_NAME_TAG];
        $taxonomy = $tags[self::TAXONOMY_TAG];
        return new self($action, $entityId, $taxonomy, $termName);
    }

    public function getCustomTags() {
        $tags = array(
            self::TERM_NAME_TAG => $this->termName,
            self::TAXONOMY_TAG => $this->taxonomy,
        );

        return $tags;
    }

    public function getTaxonomyName() {
        return str_replace("_", " ", $this->taxonomy);
    }

    public function getChangedFiles() {
        $changes = parent::getChangedFiles();
        $changes[] = array("type" => "all-storage-files", "entity" => "option"); 
        return $changes;
    }
}
