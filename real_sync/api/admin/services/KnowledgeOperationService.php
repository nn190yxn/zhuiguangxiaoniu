<?php
class KnowledgeOperationService {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }
    public function requireSchema(): void { foreach (['knowledge_import_batches','knowledge_items','knowledge_item_versions','knowledge_item_sources','knowledge_item_relations','knowledge_audit_logs'] as $table) if (!adminTableExists($this->db, $table)) throw new RuntimeException("知识库迁移未就绪：缺少 {$table}"); }
    private function query(string $sql, array $params = []): array { $stmt=$this->db->prepare($sql); foreach($params as $name=>$value) $stmt->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR)); $stmt->execute(); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    public function listBatches(): array { return $this->query('SELECT * FROM knowledge_import_batches ORDER BY created_at DESC'); }
    public function quality(?int $batchId=null): array { return $this->query('SELECT publication_status,status,content_type,domain_code,risk_level,COUNT(*) total FROM knowledge_items'.($batchId?' WHERE source_batch_id=:batch_id':'').' GROUP BY publication_status,status,content_type,domain_code,risk_level',$batchId?['batch_id'=>$batchId]:[]); }
    public function item(int $id): array { $rows=$this->query('SELECT * FROM knowledge_items WHERE id=:item_id',['item_id'=>$id]); if(!$rows) throw new RuntimeException('知识卡不存在'); $rows[0]['versions']=$this->versions($id); $rows[0]['sources']=$this->query('SELECT source_id,knowledge_item_id,batch_id,source_card_id,source_articles_json,source_images_json,raw_frontmatter_json,created_at FROM knowledge_item_sources WHERE knowledge_item_id=:item_id',['item_id'=>$id]); return $rows[0]; }
    public function relations(?int $id=null): array { return $this->query('SELECT * FROM knowledge_item_relations'.($id?' WHERE source_item_id=:source_id OR target_item_id=:target_id':'').' ORDER BY updated_at DESC',$id?['source_id'=>$id,'target_id'=>$id]:[]); }
    public function versions(int $id): array { return $this->query('SELECT version_id,knowledge_item_id,version_no,title,summary,content,content_format,content_type,domain_code,risk_level,subject,age_group,training_type,difficulty,tags_json,source_snapshot_json,change_reason,changed_by,status,created_at FROM knowledge_item_versions WHERE knowledge_item_id=:item_id ORDER BY version_no DESC',['item_id'=>$id]); }
    public function auditLogs(?int $id=null): array { return $this->query('SELECT * FROM knowledge_audit_logs'.($id?' WHERE target_id=:target_id':'').' ORDER BY created_at DESC',$id?['target_id'=>(string)$id]:[]); }
    private function recordAudit(array $actor,string $action,string $type,string $id,$before,$after,string $reason,?int $batch=null): void { $stmt=$this->db->prepare('INSERT INTO knowledge_audit_logs (batch_id,actor_user_id,actor_staff_id,action,target_type,target_id,before_json,after_json,metadata_json,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)'); $stmt->execute([$batch,$actor['user_id']??null,$actor['staff_id']??null,$action,$type,$id,json_encode($before,JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_UNICODE),json_encode(['reason'=>$reason],JSON_UNESCAPED_UNICODE),getClientIpAddress(),getRequestUserAgent()]); }
    public function reviewRelation(int $id, string $type, string $note, array $actor): void
    {
        $note = trim($note);
        if (!in_array($type, ['candidate', 'merged', 'kept_separate', 'rejected'], true) || $note === '') {
            throw new InvalidArgumentException('关系动作和说明无效');
        }
        $this->db->beginTransaction();
        try {
            $before = $this->query(
                'SELECT * FROM knowledge_item_relations WHERE relation_id = :relation_id FOR UPDATE',
                ['relation_id' => $id]
            );
            if (!$before) {
                throw new RuntimeException('关系不存在');
            }
            $stmt = $this->db->prepare(
                'UPDATE knowledge_item_relations
                 SET relation_type = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                 WHERE relation_id = ?'
            );
            $stmt->execute([$type, $actor['staff_id'] ?? $actor['user_id'] ?? null, $note, $id]);
            $this->recordAudit(
                $actor,
                'review_relation',
                'relation',
                (string)$id,
                $before[0],
                ['relation_type' => $type],
                $note
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function createVersion(int $id,array $data,array $actor): int { $reason=trim((string)($data['reason']??'')); $content=trim((string)($data['content']??'')); $format=(string)($data['content_format']??'markdown'); if($reason===''||$content==='') throw new InvalidArgumentException('reason 和 content 不能为空'); if(!in_array($format,['markdown','html'],true)) throw new InvalidArgumentException('content_format 仅允许 markdown/html'); $this->db->beginTransaction(); try { $item=$this->query('SELECT * FROM knowledge_items WHERE id=:item_id FOR UPDATE',['item_id'=>$id]); if(!$item) throw new RuntimeException('知识卡不存在'); $next=(int)$this->query('SELECT COALESCE(MAX(version_no),0)+1 AS next_no FROM knowledge_item_versions WHERE knowledge_item_id=:item_id',['item_id'=>$id])[0]['next_no']; $stmt=$this->db->prepare("INSERT INTO knowledge_item_versions (knowledge_item_id,version_no,title,summary,content,content_format,change_reason,changed_by,status) VALUES (?,?,?,?,?,?,?,?, 'active')"); $stmt->execute([$id,$next,trim((string)($data['title']??'')),$data['summary']??null,$content,$format,$reason,$actor['user_id']??null]); $versionId=(int)$this->db->lastInsertId(); $this->recordAudit($actor,'create_version','item',(string)$id,$item[0],['version_id'=>$versionId],$reason); $this->db->commit(); return $versionId; } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; } }
    private function transition(int $id,string $target,array $actor,string $reason): void { $reason=trim($reason); if($reason==='') throw new InvalidArgumentException('必须提供 reason/note'); $allowed=['published'=>['isolated','reviewing'],'reviewing'=>['published']]; $this->db->beginTransaction(); try { $rows=$this->query('SELECT * FROM knowledge_items WHERE id=:item_id FOR UPDATE',['item_id'=>$id]); if(!$rows) throw new RuntimeException('知识卡不存在'); $before=$rows[0]; if(!in_array($before['publication_status'],$allowed[$target]??[],true)) throw new RuntimeException('知识卡状态不允许该操作'); if($target==='published' && empty($before['current_version_id'])) throw new RuntimeException('发布前必须存在 current_version_id'); $stmt=$this->db->prepare('UPDATE knowledge_items SET publication_status=?, status=? WHERE id=?'); $stmt->execute([$target,$target==='published'?1:(int)$before['status'],$id]); $this->recordAudit($actor,$target,'item',(string)$id,$before,['publication_status'=>$target,'status'=>$target==='published'?1:(int)$before['status']],$reason); $this->db->commit(); } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; } }
    public function publish(int $id,array $actor,string $reason): void { $this->transition($id,'published',$actor,$reason); }
    public function unpublish(int $id,array $actor,string $reason): void { $this->transition($id,'reviewing',$actor,$reason); }
    public function publishBatch(int $batchId,array $actor,string $reason): void { $reason=trim($reason); if($reason==='') throw new InvalidArgumentException('必须提供 reason/note'); $this->db->beginTransaction(); try { $batch=$this->query('SELECT * FROM knowledge_import_batches WHERE batch_id=:batch_id FOR UPDATE',['batch_id'=>$batchId]); if(!$batch||!in_array($batch[0]['status'],['isolated','reviewing'],true)) throw new RuntimeException('批次状态不允许发布'); if($this->query('SELECT id FROM knowledge_items WHERE source_batch_id=:batch_id AND current_version_id IS NULL',['batch_id'=>$batchId])) throw new RuntimeException('批次存在缺少 current_version_id 的知识卡'); $this->db->prepare("UPDATE knowledge_import_batches SET status='reviewing' WHERE batch_id=?")->execute([$batchId]); $this->db->prepare("UPDATE knowledge_items SET publication_status='published',status=1 WHERE source_batch_id=? AND publication_status IN ('isolated','reviewing')")->execute([$batchId]); $this->db->prepare("UPDATE knowledge_import_batches SET status='published' WHERE batch_id=?")->execute([$batchId]); $this->recordAudit($actor,'publish_batch','batch',(string)$batchId,$batch[0],['status'=>'published'],$reason,$batchId); $this->db->commit(); } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; } }
    public function rollback(int $id,int $versionId,array $actor,string $reason): void { $reason=trim($reason); if($reason==='') throw new InvalidArgumentException('必须提供 reason'); $this->db->beginTransaction(); try { $item=$this->query('SELECT * FROM knowledge_items WHERE id=:item_id FOR UPDATE',['item_id'=>$id]); if(!$item) throw new RuntimeException('知识卡不存在'); $current=(int)($item[0]['current_version_id']??0); if($current===$versionId) throw new RuntimeException('目标版本不能是当前版本'); $target=$this->query('SELECT * FROM knowledge_item_versions WHERE version_id=:version_id AND knowledge_item_id=:item_id FOR UPDATE',['version_id'=>$versionId,'item_id'=>$id]); if(!$target||$target[0]['status']==='rolled_back') throw new RuntimeException('目标版本无效或已回滚'); if($current>0) $this->db->prepare("UPDATE knowledge_item_versions SET status='superseded' WHERE version_id=? AND knowledge_item_id=?")->execute([$current,$id]); $this->db->prepare("UPDATE knowledge_item_versions SET status='active' WHERE version_id=? AND knowledge_item_id=?")->execute([$versionId,$id]); $this->db->prepare("UPDATE knowledge_items SET current_version_id=?,publication_status='published',status=1 WHERE id=?")->execute([$versionId,$id]); $this->recordAudit($actor,'rollback','item',(string)$id,$item[0],['current_version_id'=>$versionId,'publication_status'=>'published','status'=>1],$reason); $this->db->commit(); } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; } }
}
