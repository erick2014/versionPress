<?php

namespace VersionPress\ChangeInfos;
use Nette\Utils\Strings;
use VersionPress\Git\CommitMessage;
use VersionPress\Utils\StringUtils;

class TranslationChangeInfo extends TrackedChangeInfo {

    private static $OBJECT_TYPE = "translation";
    const LANGUAGE_CODE_TAG = "VP-Language-Code";
    const LANGUAGE_NAME_TAG = "VP-Language-Name";
    const TRANSLATION_TYPE_TAG = "VP-Translation-Type";
    const TRANSLATION_NAME_TAG = "VP-Translation-Name";

    private $action;

    private $languageCode;

    private $languageName;

    private $type;

    private $name;

    public function __construct($action, $languageCode, $languageName,  $type = 'core', $name = null) {
        $this->action = $action;
        $this->languageCode = $languageCode ? $languageCode : 'en_US';
        $this->languageName = $languageName;
        $this->type = $type;
        $this->name = $name;
    }

    public function getEntityName() {
        return self::$OBJECT_TYPE;
    }

    public function getAction() {
        return $this->action;
    }

    public function getLanguageCode() {
        return $this->languageCode;
    }

    public static function buildFromCommitMessage(CommitMessage $commitMessage) {
        $actionTag = $commitMessage->getVersionPressTag(TrackedChangeInfo::ACTION_TAG);
        $languageCode = $commitMessage->getVersionPressTag(self::LANGUAGE_CODE_TAG);
        $languageName = $commitMessage->getVersionPressTag(self::LANGUAGE_NAME_TAG);
        $type = $commitMessage->getVersionPressTag(self::TRANSLATION_TYPE_TAG);
        $name = $commitMessage->getVersionPressTag(self::TRANSLATION_NAME_TAG);
        list(, $action) = explode("/", $actionTag, 2);
        return new self($action, $languageCode, $languageName, $type, $name);
    }

    public function getChangeDescription() {
        if ($this->action === 'activate') {
            return "Site language switched to '{$this->languageName}'";
        }

        return Strings::capitalize(StringUtils::verbToPastTense($this->action)) . " translation '{$this->languageName}'";
    }

    protected function getActionTagValue() {
        return "{$this->getEntityName()}/{$this->getAction()}";
    }

    public function getCustomTags() {
        return array(
            self::LANGUAGE_CODE_TAG => $this->languageCode,
            self::LANGUAGE_NAME_TAG => $this->languageName,
            self::TRANSLATION_TYPE_TAG => $this->type,
            self::TRANSLATION_NAME_TAG => $this->name
        );
    }

    public function getChangedFiles() {
        $path = WP_CONTENT_DIR . "/languages/";

        if ($this->type === "core") {
            $path .= "*";
        } else {
            $path .= $this->type . "s/" . $this->name . "-" . $this->languageCode . ".*";
        }

        $filesChange = array("type" => "path", "path" => $path);

        $optionChange = array("type" => "all-storage-files", "entity" => "option");

        return array($filesChange, $optionChange);
    }
}
