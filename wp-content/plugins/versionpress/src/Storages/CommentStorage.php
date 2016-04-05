<?php

namespace VersionPress\Storages;

use VersionPress\ChangeInfos\CommentChangeInfo;
use VersionPress\Database\ExtendedWpdb;
use VersionPress\Utils\EntityUtils;

class CommentStorage extends DirectoryStorage {
    

    private $database;

    function __construct($directory, $entityInfo, $wpdb) {
        parent::__construct($directory, $entityInfo);
        $this->database = $wpdb;
    }

    protected function createChangeInfo($oldEntity, $newEntity, $action = null) {

        if ($action === 'edit') {
            $diff = EntityUtils::getDiff($oldEntity, $newEntity);
        }

        if (isset($diff['comment_approved'])) { 
            if (
                ($oldEntity['comment_approved'] === 'trash' && $newEntity['comment_approved'] === 'post-trashed') ||
                ($oldEntity['comment_approved'] === 'post-trashed' && $newEntity['comment_approved'] === 'trash')
            ) {
                $action = 'edit'; 
            } elseif ($diff['comment_approved'] === 'trash') {
                $action = 'trash';
            } elseif ($oldEntity['comment_approved'] === 'trash') {
                $action = 'untrash';
            } elseif ($diff['comment_approved'] === 'spam') {
                $action = 'spam';
            } elseif ($oldEntity['comment_approved'] === 'spam') {
                $action = 'unspam';
            } elseif ($oldEntity['comment_approved'] == 0 && $newEntity['comment_approved'] == 1) {
                $action = 'approve';
            } elseif ($oldEntity['comment_approved'] == 1 && $newEntity['comment_approved'] == 0) {
                $action = 'unapprove';
            }
        }

        if ($action === 'create' && $newEntity['comment_approved'] == 0) {
            $action = 'create-pending';
        }

        $author = $newEntity["comment_author"];

        $postTable = $this->database->prefix . 'posts';
        $vpIdTable = $this->database->prefix . 'vp_id';
        $result = $this->database->get_row("SELECT post_title FROM {$postTable} JOIN {$vpIdTable} ON {$postTable}.ID = {$vpIdTable}.id WHERE vp_id = UNHEX('$newEntity[vp_comment_post_ID]')");

        $postTitle = $result->post_title;

        return new CommentChangeInfo($action, $newEntity["vp_id"], $author, $postTitle);
    }

}
