<?php
namespace VersionPress\Database;

use VersionPress\Storages\Mirror;

class WpdbMirrorBridge {

    private $mirror;

    private $dbSchemaInfo;

    private $database;
    

    private $vpidRepository;

    private $disabled;

    function __construct($wpdb, Mirror $mirror, DbSchemaInfo $dbSchemaInfo, VpidRepository $vpidRepository) {
        $this->database = $wpdb;
        $this->mirror = $mirror;
        $this->dbSchemaInfo = $dbSchemaInfo;
        $this->vpidRepository = $vpidRepository;
    }

    function insert($table, $data) {
        if ($this->disabled) {
            return;
        }

        $id = $this->database->insert_id;
        $entityInfo = $this->dbSchemaInfo->getEntityInfoByPrefixedTableName($table);

        if (!$entityInfo) {
            return;
        }

        $entityName = $entityInfo->entityName;
        $data = $this->vpidRepository->replaceForeignKeysWithReferences($entityName, $data);
        $shouldBeSaved = $this->mirror->shouldBeSaved($entityName, $data);

        if (!$shouldBeSaved) {
            return;
        }

        $data = $this->vpidRepository->identifyEntity($entityName, $data, $id);
        $this->mirror->save($entityName, $data);
    }

    function update($table, $data, $where) {
        if ($this->disabled) {
            return;
        }

        $entityInfo = $this->dbSchemaInfo->getEntityInfoByPrefixedTableName($table);

        if (!$entityInfo) {
            return;
        }

        $entityName = $entityInfo->entityName;
        $data = array_merge($where, $data);

        if (!$entityInfo->usesGeneratedVpids) { 
            $data = $this->vpidRepository->replaceForeignKeysWithReferences($entityName, $data);
            $this->mirror->save($entityName, $data);
            return;
        }

        $ids = $this->detectAllAffectedIds($entityName, $data, $where);
        $data = $this->vpidRepository->replaceForeignKeysWithReferences($entityName, $data);

        foreach ($ids as $id) {
            $this->updateEntity($data, $entityName, $id);
        }
    }

    function delete($table, $where) {
        if ($this->disabled) {
            return;
        }

        $entityInfo = $this->dbSchemaInfo->getEntityInfoByPrefixedTableName($table);

        if (!$entityInfo) return;

        $entityName = $entityInfo->entityName;

        if (!$entityInfo->usesGeneratedVpids) {
            $this->mirror->delete($entityName, $where);
            return;
        }

        $ids = $this->detectAllAffectedIds($entityName, $where, $where);

        foreach ($ids as $id) {
            $where['vp_id'] = $this->vpidRepository->getVpidForEntity($entityName, $id);
            if (!$where['vp_id']) {
                continue; 
            }

            if ($this->dbSchemaInfo->isChildEntity($entityName) && !isset($where["vp_{$entityInfo->parentReference}"])) {
                $where = $this->fillParentId($entityName, $where, $id);
            }

            $this->vpidRepository->deleteId($entityName, $id);
            $this->mirror->delete($entityName, $where);
        }
    }

    private function getIdsForRestriction($entityName, $where) {
        $idColumnName = $this->dbSchemaInfo->getEntityInfo($entityName)->idColumnName;
        $table = $this->dbSchemaInfo->getPrefixedTableName($entityName);

        $sql = "SELECT {$idColumnName} FROM {$table} WHERE ";
        $sql .= join(
            " AND ",
            array_map(
                function ($column) {
                    return "`$column` = %s";
                },
                array_keys($where)
            )
        );
        $ids = $this->database->get_col($this->database->prepare($sql, $where));
        return $ids;
    }

    private function updateEntity($data, $entityName, $id) {
        $vpId = $this->vpidRepository->getVpidForEntity($entityName, $id);

        $data['vp_id'] = $vpId;

        if ($this->dbSchemaInfo->isChildEntity($entityName)) {
            $entityInfo = $this->dbSchemaInfo->getEntityInfo($entityName);
            $parentVpReference = "vp_" . $entityInfo->parentReference;
            if (!isset($data[$parentVpReference])) {
                $table = $this->dbSchemaInfo->getPrefixedTableName($entityName);
                $parentTable = $this->dbSchemaInfo->getTableName($entityInfo->references[$entityInfo->parentReference]);
                $vpidTable = $this->dbSchemaInfo->getPrefixedTableName('vp_id');
                $parentVpidSql = "SELECT HEX(vpid.vp_id) FROM {$table} t JOIN {$vpidTable} vpid ON t.{$entityInfo->parentReference} = vpid.id AND `table` = '{$parentTable}' WHERE {$entityInfo->idColumnName} = $id";
                $parentVpid = $this->database->get_var($parentVpidSql);
                $data[$parentVpReference] = $parentVpid;
            }
        }

        $shouldBeSaved = $this->mirror->shouldBeSaved($entityName, $data);
        if (!$shouldBeSaved) {
            return;
        }

        $savePostmeta = !$vpId && $entityName === 'post'; 

        if (!$vpId) {
            $data = $this->vpidRepository->identifyEntity($entityName, $data, $id);
        }

        $this->mirror->save($entityName, $data);

        if (!$savePostmeta) {
            return;
        }

        $postmeta = $this->database->get_results("SELECT meta_id, meta_key, meta_value FROM {$this->database->postmeta} WHERE post_id = {$id}", ARRAY_A);
        foreach ($postmeta as $meta) {
            $meta['vp_post_id'] = $data['vp_id'];

            $meta = $this->vpidRepository->replaceForeignKeysWithReferences('postmeta', $meta);
            if (!$this->mirror->shouldBeSaved('postmeta', $meta)) {
                continue;
            }

            $meta = $this->vpidRepository->identifyEntity('postmeta', $meta, $meta['meta_id']);
            $this->mirror->save('postmeta', $meta);
        }
    }

    private function detectAllAffectedIds($entityName, $data, $where) {
        $idColumnName = $this->dbSchemaInfo->getEntityInfo($entityName)->idColumnName;

        if (isset($where[$idColumnName])) {
            return array($where[$idColumnName]);
        }

        return $this->getIdsForRestriction($entityName, $where);
    }

    private function fillParentId($metaEntityName, $where, $id) {
        $entityInfo = $this->dbSchemaInfo->getEntityInfo($metaEntityName);
        $parentReference = $entityInfo->parentReference;

        $parent = $entityInfo->references[$parentReference];
        $vpIdTable = $this->dbSchemaInfo->getPrefixedTableName('vp_id');
        $entityTable = $this->dbSchemaInfo->getPrefixedTableName($metaEntityName);
        $parentTable = $this->dbSchemaInfo->getTableName($parent);
        $idColumnName = $this->dbSchemaInfo->getEntityInfo($metaEntityName)->idColumnName;

        $where["vp_{$parentReference}"] = $this->database->get_var("SELECT HEX(vp_id) FROM $vpIdTable WHERE `table` = '{$parentTable}' AND ID = (SELECT {$parentReference} FROM $entityTable WHERE {$idColumnName} = $id)");
        return $where;
    }

    public function disable() {
        $this->disabled = true;
    }

}
