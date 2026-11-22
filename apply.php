<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
if (!az_is_installed()) { az_redirect('install.php'); }
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$form=az_casting_form();$error='';$submitted=false;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        az_require_csrf();
        az_casting_submit_application($_POST);
        $submitted=true;
    } catch (AzHttpError $e) {
        http_response_code($e->status);$error=$e->getMessage();
        if($e->errorCode==='form_updated'){$form=az_casting_form();}
    } catch(Throwable $e){az_log_exception($e);$error='We could not save your application. Please try again.';http_response_code(500);}
}
$member=az_current_user();
$previousAnswers=is_array($_POST['answers']??null)?$_POST['answers']:[];
$oldText=static fn(string $id):string=>is_string($previousAnswers[$id]??null)?$previousAnswers[$id]:'';
$oldField=static fn(string $name):string=>is_string($_POST[$name]??null)?$_POST[$name]:'';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Apply to become an AZHottie model or creator. Introduce yourself and tell our Arizona casting team what you would like to make."><meta name="theme-color" content="#fff"><title>Apply to model · AZHottie</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/casting.css"></head><body class="az-casting-body">
<?php az_render_universal_header('apply',$member); ?>
<main class="az-apply-layout"><section class="az-apply-intro"><div class="az-apply-intro-image" role="img" aria-label="Adult model and photographer in a bright, elegant studio"></div><div class="az-apply-intro-content"><p class="az-kicker">MODEL CASTING · ARIZONA</p><h1>Behind every great image is <em>someone unforgettable.</em></h1><p>Tell us a little about yourself. AZHottie collaborates with adult models, performers, hosts, and creative personalities on editorial shoots, comedy, lifestyle products, and entertainment.</p><div class="az-apply-points"><span>01 &nbsp; Introduce yourself</span><span>02 &nbsp; Share your creative interests</span><span>03 &nbsp; Our casting team reviews your application</span></div><p class="az-small-note">No application fee. You do not need an existing account. Project participation and any adult-themed work are discussed separately and are always optional.</p></div></section>
<section class="az-apply-form-panel" aria-labelledby="apply-title"><p class="az-kicker">YOUR FIRST INTRODUCTION</p><h2 id="apply-title">Model application</h2><p class="az-muted">Private application for AZHottie's casting team. Questions marked * are required.</p>
<?php if($submitted):?><div class="az-success" role="status"><h3>Application received.</h3><p>Thanks for introducing yourself. Our team will review your answers and contact you using the information you provided if we want to explore an opportunity.</p><a href="index.php">Return to AZHottie</a></div>
<?php else:?><?php if($error!==''):?><div class="az-error" role="alert"><?=az_h($error)?></div><?php endif; ?>
<form method="post" action="apply.php" id="casting-application-form"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><input type="hidden" name="revision" value="<?= $form['revision'] ?>"><div class="az-honeypot" aria-hidden="true"><label>Leave blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
<h3 class="az-form-section-title">The basics</h3><div class="az-fields-two"><label>Full name *<input name="name" required maxlength="120" autocomplete="name" value="<?=az_h($oldField('name'))?>"></label><label>Stage name / preferred name<input name="stageName" maxlength="120" autocomplete="nickname" value="<?=az_h($oldField('stageName'))?>"></label><label>Email address *<input name="email" type="email" maxlength="254" autocomplete="email" required value="<?=az_h($oldField('email'))?>"></label><label>Arizona city / area<input name="city" maxlength="120" placeholder="Phoenix, Tempe, Tucson…" value="<?=az_h($oldField('city'))?>"></label><label>Social handles or portfolio<input name="social" maxlength="500" placeholder="Instagram, TikTok, website…" value="<?=az_h($oldField('social'))?>"></label><label>Phone (optional)<input name="phone" type="tel" maxlength="50" autocomplete="tel" value="<?=az_h($oldField('phone'))?>"></label></div>
<h3 class="az-form-section-title">Get to know you</h3>
<?php foreach($form['questions'] as $i=>$q):?>
<fieldset class="az-application-question"><legend><span class="az-question-index"><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><?=az_h($q['title'])?><?= $q['required']?' <span class="az-required">*</span>':''?></legend>
<?php if($q['help']!==''):?><p class="az-question-help"><?=az_h($q['help'])?></p><?php endif; ?>
<?php if(in_array($q['type'],['single','multiple'],true)):?>
<?php foreach($q['options'] as $choice):?><label class="az-choice"><input type="<?= $q['type']==='single'?'radio':'checkbox' ?>" name="answers[<?=az_h($q['id'])?>]<?= $q['type']==='multiple'?'[]':'' ?>" value="<?=az_h($choice)?>" <?= $q['required']&&$q['type']==='single'?'required':'' ?> <?= ($q['type']==='single' && ($previousAnswers[$q['id']]??null)===$choice) || ($q['type']==='multiple' && in_array($choice,(array)($previousAnswers[$q['id']]??[]),true))?'checked':'' ?>><span><?=az_h($choice)?></span></label><?php endforeach;?>
<?php elseif($q['type']==='short'):?><input class="az-short-answer" name="answers[<?=az_h($q['id'])?>]" maxlength="400" <?= $q['required']?'required':'' ?> value="<?=az_h($oldText($q['id']))?>" placeholder="Your answer…">
<?php else:?><textarea name="answers[<?=az_h($q['id'])?>]" rows="4" maxlength="2500" <?= $q['required']?'required':'' ?> placeholder="Your answer…"><?=az_h($oldText($q['id']))?></textarea><?php endif;?></fieldset>
<?php endforeach; ?>
<div class="az-consent"><label><input type="checkbox" name="adultConfirmed" value="1" required><span>I confirm I am 18 years or older.</span></label><label><input type="checkbox" name="contactConsent" value="1" required><span>I agree that AZHottie may review my application and contact me about casting opportunities. My answers are private unless I separately approve publication.</span></label></div>
<button type="submit" class="az-primary-button">Send my application <span aria-hidden="true">↗</span></button><p class="az-privacy-note">Submitting does not create a model account or commit you to any type of shoot. Applications are reviewed privately by administrators.</p></form><?php endif;?></section></main>
<footer class="az-casting-footer"><a href="index.php">AZHOTTIE<span>.</span></a><span>ARIZONA · MODEL COMMUNITY</span><a href="login.php">Member sign in</a></footer>
</body></html>
