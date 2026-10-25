<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['HTTPSMS_API_KEY','HTTPSMS_FROM_NUMBER','GEMINI_API_KEY'] as $key) {
    putenv($key . '='); $_ENV[$key] = ''; $_SERVER[$key] = '';
}
$mockSmsCalls = 0;
function send_sms(string $to, string $body): array {
    $GLOBALS['mockSmsCalls']++;
    return ['ok'=>true,'message_id'=>'mock-provider-id','error'=>''];
}
require __DIR__ . '/../../../api/requests/_render.php';
require __DIR__ . '/../../../api/requests/_dispatch.php';
mysqli_report(MYSQLI_REPORT_OFF);
$name = 'capstone_requests_' . getmypid();
$admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
$directory = sys_get_temp_dir() . '/' . $name;
mkdir($directory); mkdir($directory . '/sessions');
$passed = 0; $failed = 0; $processes = [];
function check(string $name, bool $ok): void {
    global $passed,$failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}\n"; }
}
function uuid(): string { $hex = bin2hex(random_bytes(16)); return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20); }
function import_sql(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); if ($db->errno) throw new RuntimeException($db->error); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function wait_for(callable $condition): void {
    $deadline = microtime(true) + 10;
    while (!$condition()) { if (microtime(true) > $deadline) throw new RuntimeException('Barrier deadline exceeded'); usleep(1000); }
}
final class LostCommitAcknowledgement extends mysqli {
    public int $commitCount = 0;
    public int $loseAt = 1;
    public function commit(int $flags = 0, ?string $name = null): bool {
        $result = parent::commit($flags, $name);
        $this->commitCount++;
        return $this->commitCount === $this->loseAt ? false : $result;
    }
}
function start_worker(string $endpoint, array $payload, int $actor = 1, bool $barrier = false, array $extra = []): array {
    global $name,$directory,$processes;
    $base = $directory . '/' . uuid();
    $config = array_merge(['db'=>$name,'sessions'=>$directory.'/sessions','session'=>bin2hex(random_bytes(16)), 'actor'=>$actor,'endpoint'=>$endpoint,'payload'=>$payload,'result'=>$base.'.json','barrier'=>$barrier ? $base : ''], $extra);
    file_put_contents($base.'.config', json_encode($config));
    $proc = proc_open([PHP_BINARY,'-d','disable_functions=curl_init',__DIR__.'/worker.php',$base.'.config'],
        [0=>['pipe','r'],1=>['file',$base.'.json','w'],2=>['file',$base.'.err','w']], $pipes);
    fclose($pipes[0]); $worker = ['process'=>$proc,'base'=>$base,'config'=>$config]; $processes[]=$proc; return $worker;
}
function finish_worker(array $worker): array {
    $exit = proc_close($worker['process']);
    $body = file_get_contents($worker['base'].'.json');
    $result = [(int)@file_get_contents($worker['base'].'.json.status'),json_decode($body,true)];
    if ($exit !== 0 || !is_array($result[1])) throw new RuntimeException('Worker failed: '.$body.' '.file_get_contents($worker['base'].'.err'));
    return $result;
}
function post(string $endpoint, array $payload, int $actor = 1): array { return finish_worker(start_worker($endpoint,$payload,$actor)); }
function fixture(string $status = 'processing', string $payment = 'unpaid', string $path = ''): array {
    global $db;
    $ref = 'REQ-' . bin2hex(random_bytes(5));
    request_exec($db, 'INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,date_requested,status,payment_status,readiness_cycle,generated_document_path) VALUES (?,1,?,?,?,CURDATE(),?,?,?,?)',
        [$ref,'Test Resident','Barangay Clearance','Testing',$status,$payment,$status==='ready'?1:0,$path]);
    return ['reference_no'=>$ref,'id'=>(int)$db->insert_id,'expected_version'=>1];
}
function intent(array $r, array $values): array { return array_merge(['reference_no'=>$r['reference_no'],'expected_version'=>$r['expected_version'],'operation_id'=>uuid()],$values); }
function row(array $r): array { global $db; return request_rows($db,'SELECT * FROM document_requests WHERE id=?',[$r['id']])[0]; }
function count_for(array $r, string $table): int {
    global $db;
    if ($table === 'audit_log') return (int)request_rows($db,'SELECT COUNT(*) c FROM audit_log WHERE target_reference=?',[$r['reference_no']])[0]['c'];
    if ($table === 'notifications') return (int)request_rows($db,'SELECT COUNT(*) c FROM notifications WHERE body LIKE ?',['%'.$r['reference_no'].'%'])[0]['c'];
    return (int)request_rows($db,'SELECT COUNT(*) c FROM request_notification_events WHERE request_id=?',[$r['id']])[0]['c'];
}
function race(array $r, string $endpointA, array $a, string $endpointB, array $b): array {
    global $db,$observer;
    $db->begin_transaction(); request_lock($db,(string)$r['id'],true);
    $wa=start_worker($endpointA,$a,1,true); $wb=start_worker($endpointB,$b,2,true);
    wait_for(fn()=>is_file($wa['base'].'.ready') && is_file($wb['base'].'.ready'));
    touch($wa['base'].'.go'); touch($wb['base'].'.go');
    wait_for(fn()=>(int)request_rows($observer,"SELECT COUNT(*) c FROM request_operations WHERE operation_id IN (?,?) AND state='running'",[$a['operation_id'],$b['operation_id']])[0]['c'] === 2);
    $db->commit();
    return [finish_worker($wa),finish_worker($wb)];
}
try {
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$name); $db->set_charset('utf8mb4');
    $DB_NAME = $name; // Any shared helper opens only this disposable schema.
    $observer = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$name);
    $schema = file_get_contents(__DIR__.'/../../database/database.sql');
    $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS `barangay_system_test_environment`.*?;\s*USE `barangay_system_test_environment`;/s','',$schema);
    import_sql($db,$schema);
    request_exec($db,'DELETE FROM barangay_officials');
    request_exec($db,"INSERT INTO households (id,household_code,address,household_size) VALUES (1,'HH-TEST-0001','Fixture',1)");
    request_exec($db,"INSERT INTO residents (id,fname,lname,username,address,contact,password,household_id,relationship_to_household_head) VALUES (1,'Test','Resident','reqresident','Fixture','09171234567','unused',1,'Head')");
    request_exec($db,"INSERT INTO barangay_officials (id,fname,lname,username,address,contact,password) VALUES (1,'Official','One','reqofficial1','Fixture','09171234567','unused'),(2,'Official','Two','reqofficial2','Fixture','09171234567','unused')");
    check('fresh schema imports into disposable database',true);
    // Test the additive migration separately against a pre-upgrade disposable table.
    $legacy = $name . '_migration'; $admin->query("CREATE DATABASE `$legacy`");
    $migration = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$legacy);
    $old = preg_replace('/  row_version INT.*?generation_expires_at DATETIME NULL,\s*/s','',$schema);
    $old = substr($old,0,strpos($old,'CREATE TABLE request_operations'));
    import_sql($migration,$old);
    request_exec($migration,"SET FOREIGN_KEY_CHECKS=0");
    request_exec($migration,"INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,date_requested,status,generated_document_path) VALUES ('LEGACY',1,'Old','Clearance','Test','2026-01-01','ready','generated_documents/old.pdf')");
    import_sql($migration,file_get_contents(__DIR__.'/../../migrations/20261025_request_concurrency.sql'));
    $m=request_rows($migration,'SELECT * FROM document_requests')[0];
    check('additive migration preserves old path and establishes Ready cycle', (int)$m['row_version']===1 && (int)$m['readiness_cycle']===1 && $m['generated_document_path']==='generated_documents/old.pdf');
    check('migration records uncertain legacy pickup instead of silently duplicating it',request_rows($migration,'SELECT state FROM request_notification_events')[0]['state']==='uncertain');
    $migration->close(); $admin->query("DROP DATABASE `$legacy`");

    $r=fixture(); $a=intent($r,['payment_status'=>'paid']); $b=intent($r,['payment_status'=>'waived']);
    $pair=race($r,'update_payment',$a,'update_payment',$b); $codes=[$pair[0][0],$pair[1][0]]; sort($codes);
    check('overlapping Paid/Waived: one winner, one 409', $codes===[200,409] && (int)row($r)['row_version']===2 && count_for($r,'audit_log')===1);
    $r=fixture(); $pair=race($r,'update_status',intent($r,['status'=>'ready']),'update_status',intent($r,['status'=>'ready']));
    $codes=[$pair[0][0],$pair[1][0]];sort($codes);
    check('two officials same status: one transition/audit/resident notice', $codes===[200,409] && count_for($r,'audit_log')===1 && count_for($r,'notifications')===1 && (int)row($r)['readiness_cycle']===1);
    $same=intent(array_merge($r,['expected_version'=>2]),['status'=>'ready']);
    $res=post('update_status',$same);
    check('unchanged status creates no side effects', $res[0]===200 && $res[1]['changed']===false && count_for($r,'audit_log')===1 && count_for($r,'notifications')===1 && (int)row($r)['row_version']===2);
    $exact=post('update_status',$same);
    check('lost-response retry returns identical recorded outcome',$exact==$res);
    $other=$same;$other['status']='completed';
    check('operation-ID payload reuse rejected',post('update_status',$other)[1]['code']==='operation_reused');
    check('operation-ID actor reuse rejected',post('update_status',$same,2)[1]['code']==='operation_reused');
    $complete=intent(array_merge($r,['expected_version'=>2]),['status'=>'completed']); $first=post('update_status',$complete);$stamp=row($r)['date_completed'];
    check('completion changes Unpaid to Paid and stamps once',$first[0]===200 && row($r)['payment_status']==='paid' && $stamp!==null);
    check('replay precedes stale-version rejection',post('update_status',$complete)==$first);
    $same=intent(array_merge($r,['expected_version'=>3]),['status'=>'completed']);$repeat=post('update_status',$same);
    check('repeated completion keeps timestamp/counts',$repeat[1]['changed']===false && row($r)['date_completed']===$stamp && count_for($r,'audit_log')===2);
    check('terminal request cannot move backward',post('update_status',intent(array_merge($r,['expected_version'=>3]),['status'=>'ready']))[1]['code']==='invalid_transition');
    $waived=fixture('ready','waived');post('update_status',intent($waived,['status'=>'completed']));
    check('completion preserves Waived',row($waived)['payment_status']==='waived');
    $paid=fixture();post('update_payment',intent($paid,['payment_status'=>'paid']));
    check('Paid while Processing is valid',row($paid)['payment_status']==='paid' && row($paid)['status']==='processing');
    check('payment reversal forbidden',post('update_payment',intent(array_merge($paid,['expected_version'=>2]),['payment_status'=>'waived']))[0]===409);
    $noop=post('update_payment',intent(array_merge($paid,['expected_version'=>2]),['payment_status'=>'paid']));
    check('unchanged payment has no audit/version increment',$noop[1]['changed']===false && (int)row($paid)['row_version']===2 && count_for($paid,'audit_log')===1);
    $pending=fixture('pending');
    check('general endpoint cannot start Processing',post('update_status',intent($pending,['status'=>'processing']))[0]===409);
    check('cancellation requires reason',post('update_status',intent($pending,['status'=>'cancelled']))[0]===400);
    foreach ([null,0,-1,'1',1.5,[],true] as $version) {
        $p=intent($pending,['status'=>'cancelled','official_note'=>'Test','expected_version'=>$version]);
        check('reject malformed version '.json_encode($version),post('update_status',$p)[0]===422);
    }
    $p=intent($pending,['status'=>'cancelled','official_note'=>'Test']);unset($p['expected_version']);
    check('missing version rejected',post('update_status',$p)[0]===422);
    $p=intent($pending,['status'=>'cancelled','official_note'=>'Test','operation_id'=>'bad']);
    check('malformed operation UUID rejected',post('update_status',$p)[0]===422);
    check('unauthorized mutation rejected',post('update_status',intent($pending,['status'=>'cancelled','official_note'=>'Test']),0)[0]===401);

    foreach (['audit_log','notifications'] as $table) {
        $r=fixture();$db->query("CREATE TRIGGER fixture_fail BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
        $p=intent($r,['status'=>'ready']);$failedResult=post('update_status',$p);
        $db->query('DROP TRIGGER fixture_fail');
        $op=request_rows($db,'SELECT state,http_status FROM request_operations WHERE operation_id=?',[$p['operation_id']])[0];
        check("{$table} failure rolls back request and all side effects", $failedResult[0]===503 && row($r)['status']==='processing' && (int)row($r)['row_version']===1 && count_for($r,'audit_log')===0 && count_for($r,'notifications')===0 && $op['state']==='done' && (int)$op['http_status']===503);
    }

    $ready=fixture('ready');$a=intent($ready,[]);$b=intent($ready,[]);
    $pair=race($ready,'notify_ready',$a,'notify_ready',$b);
    check('different IDs create only one initial pickup intent',$pair[0][0]===200 && $pair[1][0]===200 && count_for($ready,'request_notification_events')===1 && count_for($ready,'audit_log')===1 && (int)row($ready)['row_version']===1);
    $event=(int)request_rows($db,'SELECT id FROM request_notification_events WHERE request_id=?',[$ready['id']])[0]['id'];
    $calls=0;$deliver=function()use(&$calls){$calls++;return ['status'=>'sent','reason'=>'mock'];};
    $d=request_dispatch($db,$event,$deliver);request_dispatch($db,$event,$deliver);
    check('duplicate dispatch never repeats mocked provider call',$calls===1 && $d['state']==='sent');
    check('operation replay never sends SMS',post('notify_ready',$a)==$pair[0] && $calls===1);
    $resend=intent($ready,['resend'=>true]);
    check('resend cooldown enforced',post('notify_ready',$resend)[1]['code']==='cooldown');
    request_exec($db,'UPDATE request_notification_events SET created_at=NOW()-INTERVAL 301 SECOND WHERE id=?',[$event]);
    $pair=race($ready,'notify_ready',intent($ready,['resend'=>true]),'notify_ready',intent($ready,['resend'=>true]));
    $codes=[$pair[0][0],$pair[1][0]];sort($codes);
    check('concurrent resends cannot create parallel deliveries',$codes===[200,409] && count_for($ready,'request_notification_events')===2 && count_for($ready,'audit_log')===2);
    $r=fixture('ready');
    $pair=race($r,'update_status',intent($r,['status'=>'completed']),'notify_ready',intent($r,[]));
    $sent=0;
    foreach(request_rows($db,'SELECT id FROM request_notification_events WHERE request_id=?',[$r['id']]) as $e) request_dispatch($db,(int)$e['id'],function()use(&$sent){$sent++;return ['status'=>'sent'];});
    check('completion/notification race never moves status backward or sends queued obsolete SMS',row($r)['status']==='completed' && $sent===0);
    $r=fixture('ready');$notice=post('notify_ready',intent($r,[]));$event=$notice[1]['event_id'];
    $out=request_dispatch($db,$event,fn()=>['status'=>'failed','reason'=>'Rejected','uncertain'=>false]);
    check('definitive SMS failure does not turn committed intent into mutation failure',$notice[0]===200 && $out['state']==='failed' && row($r)['status']==='ready');
    $r=fixture('ready');$notice=post('notify_ready',intent($r,[]));$event=$notice[1]['event_id'];
    $out=request_dispatch($db,$event,fn()=>['status'=>'failed','reason'=>'Timeout','uncertain'=>true]);
    check('provider timeout persists uncertain outcome',$out['state']==='uncertain');
    request_exec($db,'UPDATE request_notification_events SET created_at=NOW()-INTERVAL 301 SECOND WHERE id=?',[$event]);
    check('uncertain delivery requires reconciliation/explicit acknowledgement',post('notify_ready',intent($r,['resend'=>true]))[1]['code']==='uncertain_delivery');
    check('explicit acknowledged uncertain retry creates separate audited intent',post('notify_ready',intent($r,['resend'=>true,'acknowledge_uncertain'=>true]))[0]===200 && count_for($r,'audit_log')===2);
    $r=fixture('ready');$notice=post('notify_ready',intent($r,[]));$event=$notice[1]['event_id'];
    request_exec($db,"UPDATE request_notification_events SET state='sending',claimed_at=NOW()-INTERVAL 121 SECOND WHERE id=?",[$event]);
    request_recover($db);$out=request_dispatch($db,$event,$deliver);
    check('crashed dispatcher recovery never blindly resends',$out['state']==='uncertain' && !$out['dispatched']);
    $r=fixture('ready');$notice=post('notify_ready',intent($r,[]));$event=$notice[1]['event_id'];
    $base=$directory.'/dispatch';$callsPath=$directory.'/calls';
    $worker=start_worker('dispatch',[],1,false,['event'=>$event,'barrier'=>$base,'calls'=>$callsPath]);
    wait_for(fn()=>is_file($base.'.ready'));
    $duplicate=request_dispatch($db,$event,$deliver);touch($base.'.go');$sentResult=finish_worker($worker);
    check('overlapping dispatcher workers claim once',$duplicate['state']==='sending' && !$duplicate['dispatched'] && count(file($callsPath))===1 && $sentResult[1]['state']==='sent');

    // Generation rendering is deferred explicitly at the lease/publication boundary.
    $actor=['id'=>1,'name'=>'Official One'];
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>['full_name'=>'Test'],'expected_version'=>1,'operation_id'=>uuid()];
    $begun=request_generation_begin($db,$actor,$p);
    $second=$p;$second['operation_id']=uuid();
    check('one active generation lease per request',isset($begun['request']) && request_generation_begin($db,['id'=>2,'name'=>'Official Two'],$second)['result'][1]['code']==='generation_active');
    try { request_claim($db,$actor,'generation',(string)$p['id'],$p); $inProgress=false; } catch(RequestProblem $e) { $inProgress=$e->kind==='in_progress'; }
    check('duplicate operation while running returns explicit in-progress',$inProgress);
    post('update_status',intent($r,['status'=>'cancelled','official_note'=>'Cancelled during conversion']));
    try { request_generation_publish($db,$actor,$p,['stored_path'=>'generated_documents/new.pdf','docx_path'=>'generated_documents/new.docx']);$stale=false; }
    catch(RequestProblem $e){$stale=$e->kind==='stale_version';request_generation_abort($db,$p,$e);}
    check('generation/cancellation race rejects deferred result',$stale && row($r)['status']==='cancelled' && row($r)['generation_token']===null);
    $r=fixture('pending','unpaid','generated_documents/prior.pdf');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    request_generation_begin($db,$actor,$p);post('update_payment',intent($r,['payment_status'=>'paid']));
    try { request_generation_publish($db,$actor,$p,['stored_path'=>'generated_documents/new.pdf','docx_path'=>'generated_documents/new.docx']);$stale=false; }
    catch(RequestProblem $e){$stale=$e->kind==='stale_version';request_generation_abort($db,$p,$e);}
    check('payment during conversion rejects generation without replacing prior path',$stale && row($r)['generated_document_path']==='generated_documents/prior.pdf' && row($r)['payment_status']==='paid');
    $r=fixture('pending','unpaid','generated_documents/prior.pdf');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    request_generation_begin($db,$actor,$p);
    $db->query("CREATE TRIGGER fixture_fail BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture publication failure'");
    try{request_generation_publish($db,$actor,$p,['stored_path'=>'generated_documents/new.pdf','docx_path'=>'generated_documents/new.docx']);$rolled=false;}
    catch(Throwable $e){$rolled=true;request_generation_abort($db,$p,$e);}
    $db->query('DROP TRIGGER fixture_fail');
    check('database publication failure preserves previous path and Pending/version', $rolled && row($r)['generated_document_path']==='generated_documents/prior.pdf' && row($r)['status']==='pending' && (int)row($r)['row_version']===1 && count_for($r,'notifications')===0);
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];request_generation_begin($db,$actor,$p);
    $published=request_generation_publish($db,$actor,$p,['stored_path'=>'generated_documents/unique.pdf','docx_path'=>'generated_documents/unique.docx']);
    check('publication atomically persists path/Processing/version/audit/notice',$published[0]===200 && row($r)['status']==='processing' && (int)row($r)['row_version']===2 && count_for($r,'audit_log')===1 && count_for($r,'notifications')===1);
    check('generation lost-response replay returns exact publication',request_generation_begin($db,$actor,$p)['result']==$published);
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];request_generation_begin($db,$actor,$p);
    request_exec($db,'UPDATE request_operations SET created_at=NOW()-INTERVAL 601 SECOND WHERE operation_id=?',[$p['operation_id']]);request_recover($db);
    check('abandoned generation recovers token and records outcome',row($r)['generation_token']===null && request_generation_begin($db,$actor,$p)['result'][1]['code']==='recovered_operation');
    $prior=$directory.'/prior.pdf';file_put_contents($prior,"%PDF-1.4\nOLD VALID FILE\n%%EOF");
    $work=$directory.'/conversion';mkdir($work);
    try { request_convert([PHP_BINARY,'-r','exit(1);'],$work);$conversionFailed=false; } catch(Throwable $e){$conversionFailed=true;}
    check('old PDF cannot satisfy failed operation output',$conversionFailed && request_valid_pdf($prior) && !request_valid_pdf($work.'/new.pdf'));
    request_copy_exclusive($prior,$directory.'/published.pdf');
    try { request_copy_exclusive($prior,$directory.'/published.pdf');$exclusive=false; }catch(Throwable $e){$exclusive=true;}
    check('unique publication never overwrites existing artifact',$exclusive && file_get_contents($directory.'/published.pdf')===file_get_contents($prior));
    try { request_convert([PHP_BINARY,'-r','while(true){}'],$work,1);$timeout=false; }catch(Throwable $e){$timeout=str_contains($e->getMessage(),'timed out');}
    check('conversion has enforced process timeout',$timeout);
    $one=fixture();$two=fixture();$db->begin_transaction();request_lock($db,(string)$one['id'],true);
    $independent=post('update_payment',intent($two,['payment_status'=>'paid']));$db->rollback();
    check('different requests proceed independently',$independent[0]===200 && row($two)['payment_status']==='paid');
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];$p2=$p;$p2['operation_id']=uuid();
    $pair=race($r,'generation_begin',$p,'generation_begin',$p2);$codes=[$pair[0][0],$pair[1][0]];sort($codes);
    check('overlapping authenticated generation attempts acquire one lease',$codes===[200,409] && row($r)['generation_token']!==null);
    $winner=$pair[0][0]===200?$p:$p2;request_generation_abort($db,$winner,new RequestProblem('Fixture cleanup'));
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    $pair=race($r,'generation_begin',$p,'update_status',intent($r,['status'=>'cancelled','official_note'=>'Race cancellation']));
    $state=row($r);
    if($state['generation_token']!==null) request_generation_abort($db,$p,new RequestProblem('Cancelled during conversion',409,'stale_version'));
    check('overlapping generation/cancellation keeps cancellation authoritative',row($r)['status']==='cancelled' && row($r)['generated_document_path']==='' && (int)row($r)['row_version']===2);
    // Same ID, same official, distinct session, while original transaction is blocked.
    $r=fixture();$p=intent($r,['payment_status'=>'paid']);$db->begin_transaction();request_lock($db,(string)$r['id'],true);
    $worker=start_worker('update_payment',$p,1,true);wait_for(fn()=>is_file($worker['base'].'.ready'));touch($worker['base'].'.go');
    wait_for(fn()=>count(request_rows($observer,'SELECT operation_id FROM request_operations WHERE operation_id=?',[$p['operation_id']]))===1);
    $duplicate=post('update_payment',$p,1);$db->commit();$first=finish_worker($worker);
    check('overlapping duplicate submission returns in-progress and applies once',$duplicate[1]['code']==='in_progress' && $first[0]===200 && count_for($r,'audit_log')===1);
    mkdir($directory.'/generated_documents');
    $fakeRenderer=function($request,$fields,$workspace,$operation) {
        $pdf=$workspace.'/'.$operation.'.pdf';$docx=$workspace.'/'.$operation.'.docx';
        file_put_contents($pdf,"%PDF-1.4\nOPERATION ".$operation."\n%%EOF");file_put_contents($docx,'DOCX '.$operation);
        return ['pdf'=>$pdf,'docx'=>$docx];
    };
    $r=fixture('pending','unpaid','generated_documents/old.pdf');
    file_put_contents($directory.'/generated_documents/old.pdf',"%PDF-1.4\nPREVIOUS\n%%EOF");
    $oldBytes=file_get_contents($directory.'/generated_documents/old.pdf');
    $p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    $db->query("CREATE TRIGGER fixture_fail BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture notification failure'");
    $result=request_generation_run($db,$actor,$p,$fakeRenderer,$directory);$db->query('DROP TRIGGER fixture_fail');
    check('publication rollback deletes only unreferenced new artifacts and preserves prior bytes',$result[0]===503 && row($r)['generated_document_path']==='generated_documents/old.pdf' && file_get_contents($directory.'/generated_documents/old.pdf')===$oldBytes && !file_exists($directory.'/generated_documents/'.$p['operation_id'].'.pdf') && !file_exists($directory.'/generated_documents/'.$p['operation_id'].'.docx'));
    $p['operation_id']=uuid();$result=request_generation_run($db,$actor,$p,$fakeRenderer,$directory);
    check('successful coordinator publishes only operation-specific bytes',$result[0]===200 && file_get_contents($directory.'/'.row($r)['generated_document_path'])==="%PDF-1.4\nOPERATION ".$p['operation_id']."\n%%EOF" && file_get_contents($directory.'/generated_documents/old.pdf')===$oldBytes);
    $r=fixture('pending','unpaid','generated_documents/old.pdf');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    $result=request_generation_run($db,$actor,$p,fn()=>throw new RuntimeException('Fixture conversion failure'),$directory);
    check('conversion failure cannot use or replace an older valid PDF',$result[0]===503 && row($r)['status']==='pending' && row($r)['generated_document_path']==='generated_documents/old.pdf' && file_get_contents($directory.'/generated_documents/old.pdf')===$oldBytes);
    $r=fixture('pending','unpaid','generated_documents/old.pdf');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    file_put_contents($directory.'/generated_documents/'.$p['operation_id'].'.pdf','EXISTING COLLISION');
    $result=request_generation_run($db,$actor,$p,$fakeRenderer,$directory);
    check('filesystem publication failure preserves prior document and collision file',$result[0]===503 && row($r)['generated_document_path']==='generated_documents/old.pdf' && file_get_contents($directory.'/generated_documents/'.$p['operation_id'].'.pdf')==='EXISTING COLLISION' && !file_exists($directory.'/generated_documents/'.$p['operation_id'].'.docx'));
    $fault=new LostCommitAcknowledgement($DB_HOST,$DB_USER,$DB_PASS,$name);$fault->loseAt=2;
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    $result=request_generation_run($fault,$actor,$p,$fakeRenderer,$directory);
    check('lost publication commit acknowledgement recovers success and keeps referenced bytes',$result[0]===200 && row($r)['status']==='processing' && is_file($directory.'/'.row($r)['generated_document_path']) && count_for($r,'audit_log')===1);$fault->close();
    $fault=new LostCommitAcknowledgement($DB_HOST,$DB_USER,$DB_PASS,$name);
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];
    $result=request_generation_run($fault,$actor,$p,$fakeRenderer,$directory);
    check('lost lease commit acknowledgement cannot leave a wedged generation token',$result[0]===503 && row($r)['generation_token']===null && row($r)['status']==='pending');$fault->close();
    $fault=new LostCommitAcknowledgement($DB_HOST,$DB_USER,$DB_PASS,$name);
    $r=fixture();$p=intent($r,['payment_status'=>'paid']);$result=request_mutate($fault,$actor,'payment',$p);
    check('lost mutation commit acknowledgement returns durable success',$result[0]===200 && row($r)['payment_status']==='paid' && count_for($r,'audit_log')===1);$fault->close();
    $r=fixture('pending');$p=['id'=>$r['id'],'fields'=>[],'expected_version'=>1,'operation_id'=>uuid()];request_generation_begin($db,$actor,$p);
    request_exec($db,'UPDATE document_requests SET generation_expires_at=NOW()-INTERVAL 1 SECOND WHERE id=?',[$r['id']]);
    try{request_generation_publish($db,$actor,$p,['stored_path'=>'generated_documents/expired.pdf','docx_path'=>'generated_documents/expired.docx']);$expired=false;}
    catch(RequestProblem $e){$expired=$e->kind==='generation_expired';request_generation_abort($db,$p,$e);}
    check('expired generation token cannot publish',$expired && row($r)['status']==='pending' && (int)row($r)['row_version']===1);
    $p=['operation_id'=>uuid(),'reference_no'=>'DOES-NOT-EXIST','expected_version'=>1,'status'=>'ready'];request_claim($db,$actor,'status','DOES-NOT-EXIST',$p);
    request_exec($db,'UPDATE request_operations SET created_at=NOW()-INTERVAL 601 SECOND WHERE operation_id=?',[$p['operation_id']]);request_recover($db);
    check('abandoned missing-request claim cannot block dispatcher recovery',request_rows($db,'SELECT state FROM request_operations WHERE operation_id=?',[$p['operation_id']])[0]['state']==='done');
    $r=fixture('ready');$n=post('notify_ready',intent($r,[]));$cycle=row($r)['readiness_cycle'];
    post('update_payment',intent($r,['payment_status'=>'paid']));$n2=post('notify_ready',intent(array_merge($r,['expected_version'=>2]),[]));
    check('payment never resets readiness cycle or initial pickup uniqueness',row($r)['readiness_cycle']===$cycle && $n2[1]['changed']===false && count_for($r,'request_notification_events')===1);
    // Shared SMS decision tree is exercised with an in-process provider mock.
    putenv('HTTPSMS_API_KEY=fixture-only');putenv('HTTPSMS_FROM_NUMBER=+639171234567');
    request_exec($db,'INSERT INTO resident_sms_preferences (resident_id,requests_enabled) VALUES (1,0)');
    $r=fixture('ready');$n=post('notify_ready',intent($r,[]));$out=request_dispatch($db,$n[1]['event_id']);
    check('dispatcher preserves resident SMS opt-out',$out['state']==='skipped' && $mockSmsCalls===0);
    request_exec($db,'UPDATE resident_sms_preferences SET requests_enabled=1 WHERE resident_id=1');
    $skipped=notify_resident(1,'request_pickup_ready','Fixture','requests_enabled','invalid');
    check('shared SMS rejects invalid contacts before provider call',$skipped['status']==='skipped' && $mockSmsCalls===0);
    for($i=0;$i<5;$i++) notify_resident(1,'request_pickup_ready','Fixture','requests_enabled','09171234567');
    $r=fixture('ready');$n=post('notify_ready',intent($r,[]));$out=request_dispatch($db,$n[1]['event_id']);
    check('dispatcher preserves five-per-day SMS limit with mocked provider',$mockSmsCalls===5 && $out['state']==='skipped');
    putenv('HTTPSMS_API_KEY=');putenv('HTTPSMS_FROM_NUMBER=');
    // Real LibreOffice smoke test: private fixture workspace and no product publication.
    $work=$directory.'/libreoffice';mkdir($work);
    $sample=array_merge(row(fixture('pending')),['fname'=>'Test','lname'=>'Resident','address'=>'Fixture Address','birthdate'=>'2000-01-01']);
    $files=request_render_document($sample,['full_name'=>'Concurrency Fixture','purpose'=>'Disposable test'],$work,uuid());
    check('real isolated LibreOffice produces a new valid PDF/DOCX',request_valid_pdf($files['pdf']) && is_file($files['docx']));
} catch(Throwable $e) { $failed++; echo 'FAIL runner exception: '.$e->getMessage()."\n".$e->getTraceAsString()."\n"; }
finally {
    if (isset($db)) { $db->rollback(); $db->close(); }
    if (isset($observer)) $observer->close();
    foreach($processes as $proc) if(is_resource($proc)) {proc_terminate($proc);proc_close($proc);}
    $admin->query("DROP DATABASE IF EXISTS `$name`");
    $admin->query("DROP DATABASE IF EXISTS `{$name}_migration`");
    remove_dir_tree($directory);
}
echo "TOTAL {$passed} passed, {$failed} failed\n";exit($failed?1:0);
