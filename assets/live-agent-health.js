/** Numeric live-health observation helpers. These never control streams or inspect media. */
export function fanVideoState({connected=false,visible=true,hasVideo=false,canMeasure=false,secondsSinceFrame=0,playRejected=false,paused=false}={}){
  if(!connected||!hasVideo||!visible||!canMeasure)return 'connecting';
  if(playRejected&&paused)return 'blocked';
  if(paused)return 'connecting';
  return Number.isFinite(secondsSinceFrame)&&secondsSinceFrame>=8?'stalled':'playing';
}
export function directorObservation({cameraCount=0,healthyCameras=0,cameraOnProgram=false,cameraFault=false,programMode='slate',viewerCount=0,sentFps=null,stalledOutbound=0,directRelays=0,pageHidden=false}={}){
  const clamp=(v,max)=>Math.max(0,Math.min(max,Math.trunc(Number.isFinite(v)?v:0)));
  const fps=Number.isFinite(sentFps)?Math.max(0,Math.min(120,Math.round(sentFps*100)/100)):null;
  return {cameraCount:clamp(cameraCount,20),healthyCameras:clamp(healthyCameras,20),cameraOnProgram:Boolean(cameraOnProgram),cameraFault:Boolean(cameraFault),programMode:['single','composition','media','slate'].includes(programMode)?programMode:'slate',viewerCount:clamp(viewerCount,6),sentFps:fps,stalledOutbound:clamp(stalledOutbound,6),directRelays:clamp(directRelays,6),pageHidden:Boolean(pageHidden)};
}
