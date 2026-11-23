<?php
declare(strict_types=1);
/**
 * Deterministic, read-only troubleshooting for real phone → Director → Program
 * → fan playback → recording. Uses only existing numeric browser measurements.
 *
 * Triage never grades a broadcast as independently accepted. Its support
 * summary deliberately excludes room IDs, show names, viewer/device identities,
 * tokens, SDP, IP addresses, test notes, filenames and personal information.
 */
function az_live_triage_analyze(?array $room,?array $d,array $checks=[]):array
{
    $issues=[];
    $add=static function(string $code,int $priority,string $severity,string $stage,
        string $title,string $evidence,string $advice)use(&$issues):void{
        $issues[]=['code'=>$code,'priority'=>$priority,'severity'=>$severity,'stage'=>$stage,
            'title'=>$title,'evidence'=>$evidence,'nextCheck'=>$advice];
    };
    $live=$room!==null && ($room['status']??'')==='live';
    if($room===null){
        $add('no_broadcast',1,'info','phone_pairing','Start a test broadcast',
            'No owned broadcast was found.',
            'Open Live Director, start a private test show, then approve and connect a real phone.');
    }elseif(!$live){
        $add('broadcast_ended',1,'info','recording_playback','This show has ended',
            'Current camera and fan measurements are not available for an ended broadcast.',
            'Review prior operator observations and play the saved recording; start a new show for live connection tests.');
    }elseif($d===null){
        $add('telemetry_unavailable',0,'warning','moving_preview','Live measurements are unavailable',
            'The broadcast is on air, but no current diagnostics were returned.',
            'Keep Live Director visible and select Refresh observations. Check the Director health report before diagnosing a camera or viewer.');
    }else{
        $approved=max(0,(int)($d['phoneApproved']??0));
        $offering=max(0,(int)($d['phoneOffering']??0));
        $directorFresh=($d['directorFresh']??false)===true;
        $healthy=$d['healthyCameras']===null?null:max(0,(int)$d['healthyCameras']);
        $cameras=$d['cameraCount']===null?null:max(0,(int)$d['cameraCount']);
        $mode=(string)($d['programMode']??'');
        $viewers=max(0,(int)($d['viewerCount']??0));
        $reports=max(0,(int)($d['fanReporting']??0));
        $playing=max(0,(int)($d['fanPlaying']??0));
        $frozen=max(0,(int)($d['fanStalled']??0));
        $blocked=max(0,(int)($d['fanBlocked']??0));
        $outbound=max(0,(int)($d['outboundStalls']??0));
        $senderFps=$d['sentFps']===null?null:max(0,min(120,(float)$d['sentFps']));
        $roomExpectedViewer=($viewers>0);
        if($approved===0){
            $add('phone_unapproved',0,'warning','phone_pairing','No approved phone for this Director',
                'Zero active approved phone connections were reported.',
                'Use the Director Connect phone QR flow, approve the requesting phone, and verify Share live on that device.');
        }elseif($offering===0){
            $add('phone_no_offer',1,'warning','moving_preview','Approved phone has no recent camera offer',
                $approved.' approved connection(s), but no fresh camera signaling offer in the last 40 seconds.',
                'Open Camera Studio on the approved phone. Keep it awake, grant camera access, choose Share live, then refresh this panel.');
        }
        if(!$directorFresh){
            $add('director_missing',0,'warning','moving_preview','Director telemetry has gone stale',
                'No current Director health sample was found within the last 35 seconds.',
                'Keep the Live Director tab open and visible. Recheck browser errors, HTTPS permission and the Director health/status controls.');
        }else{
            if($cameras!==null&&$cameras>0&&$healthy===0){
                $add('preview_frozen',0,'critical','moving_preview','Camera preview frames are not healthy',
                    $cameras.' reported camera(s); zero healthy decoded previews.',
                    'Check the approved phone live preview and camera permissions. Use Reconnect feed only for the affected phone; verify moving frames before taking it to Program.');
            }elseif($cameras===0 && $approved>0 && $offering>0){
                $add('preview_missing',1,'warning','moving_preview','Director has not registered a camera preview',
                    'A phone signaling offer exists but Director reports no camera source.',
                    'Check the Director phone tile and pending WebRTC answer. Refresh signaling only for that approved phone; verify the preview before Program.');
            }
            if($mode==='slate'&&$healthy!==null&&$healthy>0){
                $add('holding_slate',2,'info','program_switch','Program is still on the holding slate',
                    $healthy.' healthy camera preview(s), but Program mode is the slate.',
                    'Use the explicit Take/Program control for the intended approved camera. Do not switch automatically.');
            }
            if($roomExpectedViewer&&$outbound>0){
                $add('sender_stalled',0,'critical','remote_fan','Program delivery is stalling to viewers',
                    $outbound.' outbound viewer video sender(s) were reported stalled.',
                    'Compare camera preview FPS against Program sender FPS. Keep Director in the foreground and verify the Program source before asking the viewer to reconnect.');
            }elseif($roomExpectedViewer&&$mode!=='slate'&&$senderFps!==null&&$senderFps<=1){
                $add('sender_low_fps',0,'critical','remote_fan','Program sender is not advancing video',
                    'At least one viewer is connected, but reported Program sender FPS is 1 or lower.',
                    'Confirm that Program is not frozen, keep Director visible, then investigate outbound WebRTC/TURN connectivity.');
            }
        }
        if($frozen>0){
            $add('fan_frozen',0,'critical','remote_fan','A fan reports frozen playback',
                $frozen.' current viewer session(s) report stalled decoded frames.',
                'After confirming Director Program FPS, use the fan Retry live control. If the issue repeats across networks, inspect TURN configuration and browser logs.');
        }
        if($blocked>0){
            $add('fan_autoplay',1,'warning','remote_fan','A fan is blocked by autoplay permissions',
                $blocked.' viewer session(s) report blocked playback.',
                'Ask the viewer to tap Play on the video and unmute audio intentionally; do not treat autoplay refusal as a network outage.');
        }
        if($roomExpectedViewer&&$reports===0){
            $add('fan_telemetry_missing',2,'info','remote_fan','Viewer has not reported decoded-frame telemetry',
                $viewers.' viewer session(s) indicated by Director, but no fresh viewer health samples were found.',
                'Open the viewer on a second device and check whether it advances frames; an absent sample alone does not prove the video is broken.');
        }
        if($viewers===0){
            $add('fan_test_needed',2,'info','remote_fan','Remote playback has not been observed',
                'Director currently reports zero connected fan sessions.',
                'Join with a second signed-in device on a different network, then verify moving Program frames and audible sound before marking those checks passed.');
        }
    }
    foreach(az_live_acceptance_steps() as $step=>$definition){
        if(($checks[$step]['result']??'untested')==='fail'){
            $add('operator_failed_'.$step,1,'warning',$step,'Operator recorded a failed acceptance step',
                $definition['title'].' is currently marked failed by the operator.',
                'Review the saved observation and repeat the corresponding physical-device test before marking it passed.');
        }
    }
    usort($issues,static fn(array $a,array $b):int=>$a['priority']<=>$b['priority']?:
        strcmp($a['code'],$b['code']));
    $activeIssues=count(array_filter($issues,static fn(array $i):bool=>$i['priority']===0));
    $warnings=count(array_filter($issues,static fn(array $i):bool=>$i['priority']===1));
    $first=$issues[0]??null;
    return [
        'state'=>$room===null?'no_session':(!$live?'ended':($activeIssues?'urgent':($warnings?'attention':'not_confirmed'))),
        'criticalCount'=>$activeIssues,'attentionCount'=>$warnings,
        'findings'=>array_slice($issues,0,12),
        'nextStep'=>$first?($first['title'].': '.$first['nextCheck']):
            'No current exception threshold fired. Verify Program, remote fan playback, audio and the saved recording on real devices; green telemetry alone is not signoff.',
        'nextChecklistStep'=>$first['stage']??'phone_pairing',
        'automatedRecoveryTriggered'=>false,'independentlyVerified'=>false,
        'basis'=>'Deterministic comparisons of recent numeric browser reports and separate operator test results. No network, audio, recording or camera intervention was performed; absence of an alert does not imply acceptance.'
    ];
}
/** Strict allowlist for a copyable, non-personal support diagnostic.
 * Avoid even the room name or ID, original observation notes or timestamps.
 */
function az_live_triage_support_summary(?array $room,?array $d,array $triage,array $completion):array
{
    $live=($room['status']??'')==='live';
    $number=static function(mixed $value):?float{
        if($value===null||!is_numeric($value))return null;
        return round(max(0,min(1000000,(float)$value)),1);
    };
    $kinds=['phoneApproved','phoneOffering','cameraCount','healthyCameras','viewerCount',
      'fanReporting','fanPlaying','fanStalled','fanBlocked','outboundStalls','savedRecordings',
      'largestSavedBytes','directorAgeSeconds','sentFps','fanDecodedFpsMax'];
    $counters=[];
    foreach($kinds as $key){
        // Ended broadcast cannot be used as evidence for active network performance.
        $counters[$key]=(!$live && $key!=='savedRecordings' && $key!=='largestSavedBytes')
            ?null:$number($d[$key]??null);
    }
    return ['format'=>'azhottie-live-support-v1','roomState'=>$room['status']??'not_started',
      'telemetryFresh'=>$live&&($d['directorFresh']??false)===true,
      'programMode'=>$live && isset($d['programMode']) && in_array($d['programMode'],['slate','single','composition','media','direct'],true)
          ?$d['programMode']:null,
      'measurements'=>$counters,
      'issueCodes'=>array_values(array_map(static fn(array $r):string=>$r['code'],
          array_slice($triage['findings']??[],0,12))),
      'operatorPassed'=>max(0,(int)($completion['operatorPassed']??0)),
      'operatorFailed'=>max(0,(int)($completion['operatorFailed']??0)),
      'operatorUntested'=>max(0,(int)($completion['unverifiedSteps']??0)),
      'hostedIndependentlyVerified'=>false,
      'disclosure'=>'No names, room identifiers, SDP, tokens, IP addresses, media, attendee data or operator notes. Browser reports and operator checks are not independent hosted acceptance.'];
}
