<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_require_admin();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
$tab=in_array(($_GET['tab']??'leads'),['leads','questions','onboarding','pipeline'],true)?(string)$_GET['tab']:'leads';
$filter=(string)($_GET['status']??'all');
$search=substr(trim((string)($_GET['search']??'')),0,120);
$id=is_string($_GET['lead']??null)?$_GET['lead']:'';$error='';$success='';$invitation='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $lock=null;
    try{
        az_require_csrf();$lock=az_community_lock();
        $action=(string)($_POST['action']??'');
        if($action==='save_questions'){
            $values=json_decode((string)($_POST['questions_json']??''),true,64,JSON_THROW_ON_ERROR);
            az_casting_save_form(['revision'=>$_POST['revision']??null,'questions'=>$values]);$success='Public application questions saved.';$tab='questions';
        }elseif($action==='save_lead'){
            az_casting_update_lead(['id'=>$_POST['id']??'','revision'=>$_POST['revision']??null,'status'=>$_POST['status']??'','notes'=>$_POST['notes']??''],(int)$user['id']);
            $success='Application review saved.';$id=(string)($_POST['id']??'');
        }elseif($action==='onboarding_checkpoint'){
            az_agent_casting_checkpoint($user,['modelId'=>filter_var($_POST['model_id']??null,FILTER_VALIDATE_INT),
                'checkpoint'=>$_POST['checkpoint']??null,'complete'=>($_POST['complete']??'')==='1']);
            $success='Onboarding checklist updated.';$tab='onboarding';
        }elseif($action==='new_followup'){
            az_talent_create_followup(['leadId'=>$_POST['lead_id']??'',
              'nextAction'=>$_POST['next_action']??'','dueAt'=>$_POST['due_at']??''],(int)$user['id']);
            $success='Private casting follow-up saved.';$id=(string)($_POST['lead_id']??'');
        }elseif($action==='finish_followup'){
            az_talent_decide_followup(['id'=>$_POST['id']??'','revision'=>$_POST['revision']??null,
              'decision'=>$_POST['decision']??'','note'=>$_POST['resolution_note']??''],(int)$user['id']);
            $success='Casting follow-up decision recorded.';$id=(string)($_POST['lead_id']??'');
        }elseif($action==='invite'){
            $invitation=az_casting_issue_invitation((string)($_POST['id']??''),(int)$user['id']);
            $success='A one-time invitation is ready to copy. It expires in seven days. Send it privately to the applicant.';
            $id=(string)($_POST['id']??'');
        }else{throw new AzHttpError(400,'Unknown casting action.','unknown_action');}
    }catch(JsonException){$error='The questionnaire data was invalid.';http_response_code(400);}
    catch(AzHttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){az_log_exception($e);$error='Unable to save the casting update. Try again.';http_response_code(500);}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
$form=az_casting_form();$lead=null;
if($id!==''){try{$lead=az_casting_lead($id);}catch(AzHttpError $e){$error=$e->getMessage();$lead=null;}}
$leads=$lead?[]:az_casting_list_leads($filter,$search);
$followups=$lead?az_talent_followups_for_lead($lead['id']):[];
$pipeline=$tab==='pipeline'?az_talent_pipeline_dashboard():null;
$statuses=['new'=>'New','contacted'=>'Contacted','screening'=>'Screening','interview'=>'Interview','approved'=>'Approved for invitation','declined'=>'Declined','converted'=>'Model account created'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Casting CRM · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/casting.css?v=1.54.0"></head><body data-page="casting" class="az-casting-admin">
<?php az_render_universal_header('casting',$user);az_render_member_layout_open('casting',$user);?>
<main class="workspace az-casting-workspace"><header class="az-casting-heading"><div><p class="az-kicker">THE TALENT PIPELINE</p><h1>Casting <em>CRM.</em></h1><p>Review applications, manage prospects, and approve model access without exposing casting details to the public.</p></div><a class="az-casting-outline" href="apply.php" target="_blank" rel="noopener">View public application ↗</a></header>
<?php if($error!==''):?><div role="alert" class="az-error"><?=az_h($error)?></div><?php endif;?><?php if($success!==''):?><div role="status" class="az-success-inline"><?=az_h($success)?></div><?php endif;?>
<nav class="az-casting-tabs" aria-label="Casting area"><a href="casting.php" class="<?= $tab==='leads'?'active':'' ?>">Applicant CRM</a><a href="casting.php?tab=questions" class="<?= $tab==='questions'?'active':'' ?>">Edit public questions</a><a href="casting.php?tab=onboarding" class="<?= $tab==='onboarding'?'active':'' ?>">Model onboarding</a><a href="casting.php?tab=pipeline" class="<?= $tab==='pipeline'?'active':'' ?>">Talent Pipeline</a></nav>
<?php if($tab==='onboarding'): $onboarding=az_agent_casting_onboarding_list($user); ?>
<section class="az-casting-card az-agent-onboarding"><div class="az-card-heading"><div><p class="az-kicker">AGENT · ONBOARDING</p><h2>Model onboarding</h2><p>Verified model bio and availability fields update from Account Settings. An administrator confirms studio orientation and production readiness. No checklist automatically approves a person for an assignment.</p></div><a class="az-casting-outline" href="agent-chat.php?topic=casting">Discuss with Agent ↗</a></div>
<?php if(!$onboarding):?><div class="az-empty-leads">No converted models yet. Approved applicants join here after accepting a private invitation.</div><?php endif;?>
<div class="az-agent-onboarding-grid">
<?php foreach($onboarding as $model):?><article class="az-agent-onboarding-card"><div class="az-agent-onboarding-top"><h3><?=az_h($model['label'])?></h3><span class="az-count-pill"><?=$model['complete']?>/<?=$model['total']?> complete</span></div>
<?php foreach($model['items'] as $step):?><div class="az-agent-onboarding-step"><span aria-hidden="true"><?= $step['done']?'✓':'○' ?></span><span><?=az_h($step['label'])?></span>
<?php if($step['canConfirm']):?><form method="post" action="casting.php?tab=onboarding"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="onboarding_checkpoint"><input type="hidden" name="model_id" value="<?=$model['modelId']?>"><input type="hidden" name="checkpoint" value="<?=az_h($step['key'])?>"><input type="hidden" name="complete" value="<?=$step['done']?'0':'1'?>"><button type="submit" class="az-casting-outline" aria-label="<?=$step['done']?'Undo':'Confirm'?> <?=az_h($step['label'])?> for <?=az_h($model['label'])?>"><?=$step['done']?'Reopen':'Confirm'?></button></form><?php endif;?></div><?php endforeach; ?>
</article><?php endforeach;?></div></section>
<?php elseif($tab==='pipeline' && $pipeline!==null): ?>
<section class="az-casting-card az-talent-hub" aria-labelledby="talent-hub-title">
  <div class="az-card-heading"><div><p class="az-kicker">STAFF WORKFLOW · AUDITABLE</p><h2 id="talent-hub-title">Talent Pipeline &amp; Follow-ups</h2>
    <p>Existing human-reviewed casting stages, private follow-up deadlines, onboarding and public-video performance in one place. No automatic applicant ranking or approval.</p></div><a class="az-casting-outline" href="productions.php">Production assignments ↗</a></div>
  <div class="az-talent-stats" aria-label="Casting stage counts">
  <?php foreach($pipeline['counts'] as $stage=>$count): ?><a href="casting.php?status=<?=az_h($stage)?>"><strong><?=$count?></strong><span><?=az_h($statuses[$stage]??$stage)?></span></a><?php endforeach; ?></div>
  <div class="az-talent-grid">
    <section class="az-talent-column" aria-labelledby="talent-due-title"><h3 id="talent-due-title">Next actions &amp; deadlines</h3>
      <?php if(!$pipeline['tasks']):?><p>No open staff follow-ups. Create one from an application detail.</p><?php endif; ?>
      <?php foreach($pipeline['tasks'] as $task): ?><article class="az-talent-task">
        <div><a href="casting.php?lead=<?=az_h($task['lead_id'])?>"><strong><?=az_h($task['stage_name']?:$task['applicant_name'])?></strong></a>
          <span class="az-talent-state"><?=az_h($task['lead_status'])?></span></div>
        <p><?=az_h($task['next_action'])?></p><small class="<?= $task['overdue']?'az-talent-overdue':'' ?>">Due <?=az_h((new DateTimeImmutable($task['due_at']))->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j, Y g:i A'))?> AZ<?= $task['overdue']?' · OVERDUE':'' ?></small>
      </article><?php endforeach; ?></section>
    <section class="az-talent-column" aria-labelledby="talent-roster-title"><h3 id="talent-roster-title">Converted creators &amp; performance</h3>
      <?php if(!$pipeline['roster']):?><p>No model accounts converted from the casting pipeline yet.</p><?php endif; ?>
      <?php foreach($pipeline['roster'] as $member): ?><article class="az-talent-task">
        <a href="casting.php?lead=<?=az_h($member['lead_id'])?>"><strong><?=az_h($member['stage_name']?:$member['applicant_name'])?></strong></a>
        <p><?= (int)$member['public_videos'] ?> published creator videos · <?= (int)$member['recent_starts'] ?> recorded play starts in 30 days</p>
        <small>Onboarding: <?= (int)($member['onboarding']['complete']??0) ?>/<?= (int)($member['onboarding']['total']??4) ?> checkpoints</small>
        <div class="az-talent-links"><a href="creator-analytics.php?model=<?= (int)$member['converted_user_id'] ?>">View verified performance ↗</a><a href="casting.php?tab=onboarding">Onboarding ↗</a><a href="productions.php">Assignments ↗</a></div>
      </article><?php endforeach; ?></section>
  </div><p class="az-talent-basis"><?=az_h($pipeline['basis'])?></p>
</section>
<?php elseif($tab==='questions'):?>
<section class="az-casting-card"><div class="az-card-heading"><div><p class="az-kicker">PUBLIC MODEL APPLICATION</p><h2>Questions & answer choices</h2><p>Add, remove or reorder questions, choose their answer type, and edit every multiple-choice answer. Previous applications retain the exact questionnaire they answered.</p></div><a class="az-casting-outline" href="apply.php" target="_blank" rel="noopener">Preview public form ↗</a></div>
<form id="az-question-editor" method="post" action="casting.php?tab=questions"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="save_questions"><input type="hidden" name="revision" value="<?= $form['revision'] ?>"><input type="hidden" name="questions_json" id="az-questions-json"><div class="az-question-editor-summary"><strong id="az-editor-count"></strong><p>Maximum 32 questions; 12 answers per multiple-choice question.</p></div><div id="az-question-editor-list"></div><div class="az-editor-actions"><button type="button" id="az-add-question" class="az-casting-outline">+ Add question</button><button type="submit" class="az-primary-button">Save application questions</button></div><div role="alert" id="az-builder-error" class="az-error" hidden></div></form>
<script type="application/json" id="az-question-editor-data"><?=json_encode($form['questions'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)?></script><script defer src="assets/casting-builder.js"></script></section>
<?php elseif($lead):$questions=json_decode($lead['questions_json'],true);$answers=json_decode($lead['answers_json'],true);$analysis=az_agent_casting_lead_checklist($lead,time());?>
<div class="az-lead-toolbar"><a href="casting.php">← All applications</a><span class="az-status az-status-<?=az_h($lead['status'])?>"><?=az_h($statuses[$lead['status']]??$lead['status'])?></span></div>
<section class="az-casting-card az-agent-casting-lead"><div class="az-card-heading"><div><p class="az-kicker">AGENT · APPLICATION REVIEW</p><h2>Verified review checklist</h2><p>This is a completeness and workflow summary, not a candidate score. Read the questionnaire and make all casting decisions yourself.</p></div><a class="az-casting-outline" href="agent-chat.php?topic=casting">Discuss pipeline with Agent ↗</a></div><div class="az-agent-review-stats"><span><strong><?=$analysis['answeredRequired']?>/<?=$analysis['requiredCount']?></strong> required answers present</span><span><strong><?=($analysis['daysSinceUpdate']??'—')?></strong> days since stage update</span></div>
<p class="az-agent-review-suggestion"><strong>Self-reported experience:</strong> <?=az_h($analysis['experience'])?> · <strong>General availability:</strong> <?=az_h(implode(', ',$analysis['availability'])?:'Not provided')?>. Confirm availability directly for each project; this is not a suitability score.</p>
<?php if($analysis['missingRequired']):?><p>Required answers requiring review: <?=az_h(implode(' · ',$analysis['missingRequired']))?></p><?php endif;?>
<?php foreach($analysis['recommendations'] as $recommendation):?><p class="az-agent-review-suggestion"><strong><?=az_h($recommendation['title'])?></strong> · <?=az_h($recommendation['detail'])?></p><?php endforeach;?></section>
<div class="az-casting-columns"><section class="az-casting-card"><p class="az-kicker">APPLICATION · <?=az_h(substr($lead['id'],0,8))?></p><h2><?=az_h($lead['applicant_name'])?></h2><dl class="az-lead-details"><div><dt>Stage name</dt><dd><?=az_h($lead['stage_name']?:'—')?></dd></div><div><dt>Email</dt><dd><?=az_h($lead['email'])?></dd></div><div><dt>Phone</dt><dd><?=az_h($lead['phone']?:'—')?></dd></div><div><dt>Area</dt><dd><?=az_h($lead['city']?:'—')?></dd></div><div><dt>Social links</dt><dd class="az-wrap-anywhere"><?=az_h($lead['social_handles']?:'—')?></dd></div><div><dt>Applied</dt><dd><?=az_h($lead['created_at'])?></dd></div></dl><h3 class="az-section-divider">Questionnaire answers</h3>
<?php foreach($questions as $question):$answer=$answers[$question['id']]??'';?><div class="az-lead-answer"><p><?=az_h($question['title'])?></p><div><?=is_array($answer)?az_h(implode(' · ',$answer)):nl2br(az_h((string)$answer))?></div></div><?php endforeach; ?></section>
<aside class="az-casting-card"><p class="az-kicker">CASTING MANAGEMENT</p><h2>Review & next steps</h2><form method="post" action="casting.php?lead=<?=az_h($lead['id'])?>"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="save_lead"><input type="hidden" name="id" value="<?=az_h($lead['id'])?>"><input type="hidden" name="revision" value="<?=$lead['revision']?>"><label class="az-admin-field">Pipeline status<select name="status" <?= $lead['status']==='converted'?'disabled':''?>><?php foreach($statuses as $key=>$label):if($key==='converted' && $lead['status']!=='converted')continue;?><option value="<?=az_h($key)?>" <?= $lead['status']===$key?'selected':''?>><?=az_h($label)?></option><?php endforeach;?></select></label><?php if($lead['status']==='converted'):?><input type="hidden" name="status" value="converted"><?php endif;?><label class="az-admin-field">Private reviewer notes<textarea name="notes" rows="8" maxlength="10000"><?=az_h($lead['admin_notes'])?></textarea></label><button class="az-primary-button" type="submit">Save review</button></form>
<?php if($lead['status']==='approved'):?><div class="az-invite-panel"><h3>Invite as a model</h3><p>After approval, create a private one-time link. The applicant chooses their password and receives a model account linked to a studio profile. Existing users must sign into their matching email account to accept.</p><form method="post" action="casting.php?lead=<?=az_h($lead['id'])?>"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="invite"><input type="hidden" name="id" value="<?=az_h($lead['id'])?>"><button type="submit" class="az-casting-outline">Generate invitation link</button></form>
<?php if($invitation!==''):?><label class="az-admin-field">Copy invitation to send privately<input id="casting-invite-url" type="text" readonly value="<?=az_h($invitation)?>"></label><button type="button" class="az-primary-button" id="casting-copy-invite">Copy link</button><script>document.getElementById('casting-invite-url').value=new URL(document.getElementById('casting-invite-url').value,location.href).href;document.getElementById('casting-copy-invite').onclick=async()=>{const f=document.getElementById('casting-invite-url');try{await navigator.clipboard.writeText(f.value);document.getElementById('casting-copy-invite').textContent='Copied';}catch(e){f.select();}};</script><?php endif;?></div><?php endif;?>
<?php if($lead['status']==='converted'):?><p class="az-success-inline">This applicant has an approved model account. <a href="accounts.php">Manage account ↗</a> · <a href="creator-analytics.php?model=<?= (int)$lead['converted_user_id'] ?>">Creator performance ↗</a> · <a href="productions.php">Production assignments ↗</a></p><?php endif;?></aside></div>
<section class="az-casting-card az-talent-followup" aria-labelledby="casting-followup-title">
  <div class="az-card-heading"><div><p class="az-kicker">STAFF FOLLOW-THROUGH</p><h2 id="casting-followup-title">Private follow-ups</h2><p>Record a next action and deadline. Notes are private and do not automatically contact or approve anyone.</p></div><a href="casting.php?tab=pipeline" class="az-casting-outline">View Talent Pipeline ↗</a></div>
  <form method="post" action="casting.php?lead=<?=az_h($lead['id'])?>" class="az-talent-form">
    <input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="new_followup"><input type="hidden" name="lead_id" value="<?=az_h($lead['id'])?>">
    <label>Next staff action<input name="next_action" maxlength="240" minlength="5" required placeholder="Confirm interview availability / request revised portfolio"></label>
    <label>Due in Arizona time<input type="datetime-local" name="due_at" required></label>
    <button class="az-primary-button" type="submit">Save follow-up</button>
  </form>
  <div class="az-talent-followups">
  <?php foreach($followups as $task): ?><article class="az-talent-task"><div class="az-talent-task-top"><strong><?=az_h($task['next_action'])?></strong><span class="az-talent-state"><?=az_h($task['state'])?></span></div>
    <small>Due <?=az_h((new DateTimeImmutable($task['due_at']))->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j, Y g:i A'))?> · Created <?=az_h(substr($task['created_at'],0,10))?></small>
    <?php if($task['resolution_note']!==''):?><p><strong>Private result:</strong> <?=az_h($task['resolution_note'])?></p><?php endif; ?>
    <?php if($task['state']==='open'):?><form method="post" action="casting.php?lead=<?=az_h($lead['id'])?>" class="az-talent-resolve">
      <input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="action" value="finish_followup"><input type="hidden" name="lead_id" value="<?=az_h($lead['id'])?>"><input type="hidden" name="id" value="<?=az_h($task['id'])?>"><input type="hidden" name="revision" value="<?= (int)$task['revision'] ?>">
      <label>Private completion note<textarea name="resolution_note" rows="2" maxlength="1200" placeholder="Document the confirmed outcome before completing"></textarea></label>
      <div class="az-talent-links"><button class="az-casting-outline" name="decision" value="done">Mark complete</button><button class="az-casting-outline" name="decision" value="cancelled">Cancel follow-up</button></div></form><?php endif; ?>
  </article><?php endforeach; ?>
  <?php if(!$followups):?><p class="az-empty-leads">No scheduled staff follow-ups for this application.</p><?php endif; ?>
  </div>
</section>
<?php else:?>
<section class="az-casting-card"><div class="az-card-heading"><div><p class="az-kicker">APPLICANT DIRECTORY</p><h2>People interested in AZHottie</h2><p>New public submissions enter here without an account. Only approved invitations can create model accounts.</p></div><span class="az-count-pill"><?=count($leads)?> shown</span></div><form class="az-casting-search" method="get" action="casting.php"><input name="search" type="search" placeholder="Search names, emails or cities…" aria-label="Search casting applications" maxlength="120" value="<?=az_h($search)?>"><input type="hidden" name="status" value="<?=az_h($filter)?>"><button class="az-casting-outline" type="submit">Search</button></form><div class="az-casting-filters"><a class="<?= $filter==='all'?'active':'' ?>" href="casting.php">All</a><?php foreach($statuses as $key=>$label):?><a class="<?= $filter===$key?'active':'' ?>" href="casting.php?status=<?=az_h($key)?>"><?=az_h($label)?></a><?php endforeach;?></div><div class="az-lead-list"><?php if(!$leads):?><div class="az-empty-leads"><h3>No applications in this view.</h3><p>Share the public casting form with interested Arizona creators.</p><a href="apply.php">Open application ↗</a></div><?php endif;?><?php foreach($leads as $item):?><a href="casting.php?lead=<?=az_h($item['id'])?>" class="az-lead-row"><span class="az-lead-avatar" aria-hidden="true"><?=az_h(strtoupper(substr($item['stage_name']?:$item['applicant_name'],0,1)))?></span><span class="az-lead-name"><strong><?=az_h($item['applicant_name'])?></strong><small><?=az_h(($item['stage_name']?:$item['city']) ?: $item['email'])?></small></span><span class="az-status az-status-<?=az_h($item['status'])?>"><?=az_h($statuses[$item['status']]??$item['status'])?></span><span class="az-lead-date"><?=az_h(substr($item['created_at'],0,10))?></span><span class="az-lead-arrow" aria-hidden="true">↗</span></a><?php endforeach;?></div></section>
<?php endif;?></main><?php az_render_member_layout_close(); ?><footer class="studio-footer"><span>AZHOTTIE · CASTING CRM</span><span>Private team access</span></footer></body></html>
