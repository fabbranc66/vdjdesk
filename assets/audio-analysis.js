let audioAnalysisLoaded=false;
let audioAnalysisSearchTimer=null;
let audioAnalysisTracks=new Map();
let audioAnalysisPreview=null;
let audioAnalysisPreviewTimer=null;
let audioAnalysisPreviewButton=null;
let audioAnalysisPreviewFrame=null;
let audioAnalysisPreviewWaveform=null;
let audioAnalysisPreviewStart=0;
let audioAnalysisPreviewEnd=0;
let audioAnalysisStoppedPosition=null;
let audioDropDrag=null;
let audioOverviewDrag=null;
let audioWaveformSequence=0;
let audioAnalysisCurrentResult=null;
let audioAnalysisRunTargetSlot=1;
let audioAnalysisOpenRequest=0;
const audioStemVisibility=new Map();
const audioStemStates=new Map();
const audioAnalysisResults=new Map();
const audioPanelViewStates=new Map();
let audioSelectedStemLayer='';
let audioSelectedStemTrackId=0;
let audioAnalysisActivePanelId='audio-analysis-result';
let audioComparisonPreviews=[];
let audioComparisonPreviewFrame=null;
let audioTrackShiftDrag=null;
let audioLinkedScroll=false;
let audioTransitionAudios=[];
let audioTransitionTimer=null;
let audioTransitionFrame=null;
let audioTempoSyncPair=null;
let audioAutomixPoints=null;
let audioAutomixPoiDrag=null;
const audioStemCueLabels={
  vocal:['VOX','NOVOX'],
  hihat:['BUILD-UP','DROP'],
  bass:['BASS','NOBASS'],
  instruments:['SOUND','NOSOUND'],
  kick:['GROOVE','BREAK'],
};

const audioStatusToast=$('#app-toast');
const audioStatusObserver=audioStatusToast?new MutationObserver(()=>{
  if(!$('#view-audio-analysis')?.classList.contains('active'))return;
  const message=audioStatusToast.textContent.trim();
  const status=$('#audio-analysis-status');
  if(!message||!status)return;
  status.querySelector('span').textContent=message;
  status.querySelector('time').textContent=new Date().toLocaleTimeString('it-IT',{hour12:false});
}):null;
if(audioStatusObserver)audioStatusObserver.observe(audioStatusToast,{childList:true,characterData:true,subtree:true});

function audioBeatDuration(result=audioAnalysisCurrentResult){
  const vdjInterval=Number(result?.vdj_beat_interval_seconds||0);
  if(vdjInterval>0)return vdjInterval;
  const bpm=Number(result?.bpm||0);
  return bpm>0?60/bpm:0;
}

function audioPhraseGridOffset(result=audioAnalysisCurrentResult){
  const localOffset=Number(result?.grid_offset_seconds||0);
  const rawVdjPhase=result?.vdj_grid_phase_seconds;
  const vdjPhase=rawVdjPhase===null||rawVdjPhase===undefined||rawVdjPhase===''?NaN:Number(rawVdjPhase);
  const phraseDuration=audioBeatDuration(result)*16;
  if(!Number.isFinite(vdjPhase)||phraseDuration<=0)return localOffset;
  return vdjPhase-Math.round((vdjPhase-localOffset)/phraseDuration)*phraseDuration;
}

function audioLayerSections(result,key,duration){
  if(!key)return [];
  if(!result.layer_sections||typeof result.layer_sections!=='object')result.layer_sections={};
  if(!Array.isArray(result.layer_sections[key])||!result.layer_sections[key].length){
    result.layer_sections[key]=[{start:0,end:duration,label:audioStemCueLabels[key]?.[0]||key.toUpperCase(),manual:true}];
  }
  return result.layer_sections[key];
}

function audioMusicalPosition(time,result=audioAnalysisCurrentResult){
  const bpm=Number(result?.bpm||0);
  if(bpm<=0)return '0.0';
  const beatDuration=audioBeatDuration(result);
  const gridOffset=audioPhraseGridOffset(result);
  const totalBeats=Math.round((Number(time||0)-gridOffset)/beatDuration);
  const bars=Math.trunc(totalBeats/4);
  const beats=Math.abs(totalBeats-bars*4);
  return `${bars}.${beats}`;
}

function audioAbsoluteBeatPosition(time,result=audioAnalysisCurrentResult){
  const bpm=Number(result?.bpm||0);
  if(bpm<=0)return '0.000';
  const beatDuration=audioBeatDuration(result);
  const gridOffset=Number(result?.vdj_grid_phase_seconds??result?.grid_offset_seconds??0);
  return ((Number(time||0)-gridOffset)/beatDuration).toFixed(3);
}

const audioTime=value=>{
  const seconds=Math.max(0,Number(value||0));
  return `${Math.floor(seconds/60)}:${String(Math.floor(seconds%60)).padStart(2,'0')}`;
};

const audioPreciseTime=value=>{
  const seconds=Math.max(0,Number(value||0));
  return `${Math.floor(seconds/60)}:${(seconds%60).toFixed(2).padStart(5,'0')}`;
};

const parseAudioTime=value=>{
  const parts=String(value||'').trim().replace(',','.').split(':').map(Number);
  if(parts.some(part=>!Number.isFinite(part)))return NaN;
  if(parts.length===1)return parts[0];
  if(parts.length===2)return parts[0]*60+parts[1];
  return parts[0]*3600+parts[1]*60+parts[2];
};

async function searchAudioAnalysisTracks(){
  const select=$('#audio-analysis-track');
  if(!select)return;
  const query=$('#audio-analysis-search')?.value.trim()||'';
  select.innerHTML='<option value="">Ricerca nella libreria completa...</option>';
  const data=await api(`audio-analysis-tracks&q=${encodeURIComponent(query)}&limit=200`);
  audioAnalysisTracks=new Map((data.items||[]).map(track=>[Number(track.id),track]));
  select.innerHTML=data.items?.length
    ? data.items.map(track=>`<option value="${track.id}">${escapeHtml(track.artist||'')} - ${escapeHtml(track.title||'')}${track.analysis_status==='complete'?' [analizzato]':''}</option>`).join('')
    : '<option value="">Nessun brano trovato</option>';
  select.value='';
  $('#audio-track-overlay')?.classList.remove('hidden');
}

async function selectAudioAnalysisTrack(){
  const trackId=Number($('#audio-analysis-track')?.value||0);
  const track=audioAnalysisTracks.get(trackId);
  $('#audio-analysis-run').disabled=!trackId;
  $('#audio-analysis-selection').textContent=track?`${track.artist} - ${track.title}`:'Seleziona un brano.';
  if(!trackId)return;
  audioAnalysisRunTargetSlot=1;
  delete $('#audio-analysis-result')?.dataset.playlistTrackId;
  $('#audio-analysis-result-2')?.classList.add('hidden');
  $('#view-audio-analysis')?.classList.remove('audio-comparison-mode');
  $('#audio-analysis-search').value=`${track.artist||''} - ${track.title||''}`;
  $('#audio-track-overlay')?.classList.add('hidden');
  const result=await api(`audio-analysis-result&id=${trackId}`);
  renderAudioAnalysis(result,track);
}

function audioMetric(label,value,suffix=''){
  const visible=value===null||value===undefined||value===''?'--':`${value}${suffix}`;
  return `<div class="audio-metric"><span>${escapeHtml(label)}</span><b>${escapeHtml(visible)}</b></div>`;
}

function audioCompareValue(value,suffix=''){
  return value===null||value===undefined||value===''?'--':`${value}${suffix}`;
}

function audioDifference(localValue,referenceValue,suffix=''){
  if(localValue===null||localValue===undefined||referenceValue===null||referenceValue===undefined||referenceValue==='')return '--';
  const difference=Number(localValue)-Number(referenceValue);
  if(!Number.isFinite(difference))return '--';
  return `${difference>0?'+':''}${difference.toFixed(1)}${suffix}`;
}

function audioEnergyColor(value){
  const energy=Number(value||0);
  if(energy>=88)return '#ff7043';
  if(energy>=72)return '#ffd166';
  if(energy>=52)return '#44d69b';
  return '#328ce8';
}

function audioWaveform(samples,windowStart,windowEnd,dropStart,dropEnd,bpm,interactive=true){
  const values=Array.isArray(samples)?samples.map(Number).filter(Number.isFinite):[];
  if(!values.length)return '';
  const width=values.length*4,center=32;
  const gradientId=`audio-waveform-gradient-${++audioWaveformSequence}`;
  const gradientStep=Math.max(1,Math.floor(values.length/18));
  const gradientStops=values.filter((value,index)=>index%gradientStep===0||index===values.length-1).map((value,index,array)=>`<stop offset="${array.length===1?0:index/(array.length-1)*100}%" stop-color="${audioEnergyColor(value)}"></stop>`).join('');
  const windowDuration=Math.max(.01,Number(windowEnd)-Number(windowStart));
  const dropLeft=Math.max(0,Math.min(100,(Number(dropStart)-Number(windowStart))/windowDuration*100));
  const dropWidth=Math.max(0,Math.min(100-dropLeft,(Number(dropEnd)-Number(dropStart))/windowDuration*100));
  const beatDuration=Number(bpm)>0?60/Number(bpm):0;
  const beatLines=[];
  if(beatDuration>0){
    const beatStride=windowDuration>180?16:windowDuration>60?4:1;
    let beatTime=Number(dropStart);
    while(beatTime-beatDuration>=Number(windowStart))beatTime-=beatDuration;
    let beatIndex=Math.round((beatTime-Number(dropStart))/beatDuration);
    while(beatIndex%beatStride!==0){beatTime+=beatDuration;beatIndex++}
    for(;beatTime<=Number(windowEnd)+.001;beatTime+=beatDuration*beatStride,beatIndex+=beatStride){
      const left=(beatTime-Number(windowStart))/windowDuration*100;
      const type=beatIndex%16===0?'phrase':beatIndex%4===0?'bar':'beat';
      beatLines.push(`<i class="${type}" style="left:${left}%"></i>`);
    }
  }
  const top=values.map((value,index)=>`${index*4+2},${center-Math.max(2,Math.min(27,value*.27))}`);
  const bottom=[...values].reverse().map((value,reverseIndex)=>`${(values.length-1-reverseIndex)*4+2},${center+Math.max(2,Math.min(27,value*.27))}`);
  const handles=interactive?`<i class="audio-drop-handle start" data-drop-handle="start" data-time="${audioPreciseTime(dropStart)}"></i><i class="audio-drop-handle end" data-drop-handle="end" data-time="${audioPreciseTime(dropEnd)}"></i>`:'';
  return `<div class="audio-waveform-wrap" data-waveform-start="${Number(windowStart||0)}" data-waveform-end="${Number(windowEnd||0)}"><div class="audio-waveform-beats">${beatLines.join('')}</div><div class="audio-waveform-played"></div><div class="audio-waveform-drop-range" style="left:${dropLeft}%;width:${dropWidth}%">${handles}</div><svg class="audio-waveform" viewBox="0 0 ${width} 64" preserveAspectRatio="none" aria-label="Waveform estesa del cue point"><defs><linearGradient id="${gradientId}" x1="0%" x2="100%">${gradientStops}</linearGradient></defs><line class="audio-waveform-midline" x1="0" y1="${center}" x2="${width}" y2="${center}"></line><polygon style="fill:url(#${gradientId})" points="${[...top,...bottom].join(' ')}"></polygon></svg><span class="audio-waveform-time start">${audioPreciseTime(windowStart)}</span><span class="audio-waveform-time end">${audioPreciseTime(windowEnd)}</span><i class="audio-waveform-playhead"><b></b></i></div>`;
}

function audioOverview(result,trackId){
  const values=Array.isArray(result.overview_waveform)?result.overview_waveform.map(Number).filter(Number.isFinite):[];
  if(!values.length)return '';
  const duration=Math.max(.01,Number(result.duration_seconds||0));
  const width=values.length*2,center=38;
  const gradientId=`audio-overview-gradient-${++audioWaveformSequence}`;
  const gradientStep=Math.max(1,Math.floor(values.length/40));
  const stops=values.filter((value,index)=>index%gradientStep===0||index===values.length-1).map((value,index,array)=>`<stop offset="${array.length===1?0:index/(array.length-1)*100}%" stop-color="${audioEnergyColor(value)}"></stop>`).join('');
  const top=values.map((value,index)=>`${index*2+1},${center-Math.max(2,Math.min(33,value*.33))}`);
  const bottom=[...values].reverse().map((value,reverseIndex)=>`${(values.length-1-reverseIndex)*2+1},${center+Math.max(2,Math.min(33,value*.33))}`);
  const stemWaveforms=Array.isArray(result.stem_waveforms)?result.stem_waveforms:[];
  const availableStemKeys=stemWaveforms.map(layer=>layer.key);
  const storedSelected=availableStemKeys.includes(result.selected_stem_layer)?result.selected_stem_layer:'';
  const savedStemState=audioStemStates.get(trackId);
  audioStemVisibility.clear();
  if(savedStemState?.visibility)savedStemState.visibility.forEach((value,key)=>audioStemVisibility.set(key,value));
  audioSelectedStemTrackId=trackId;
  audioSelectedStemLayer=savedStemState?.selected??(storedSelected||(availableStemKeys.includes('vocal')?'vocal':availableStemKeys[0]||''));
  if(audioSelectedStemLayer&&!availableStemKeys.includes(audioSelectedStemLayer))audioSelectedStemLayer='';
  if(audioSelectedStemLayer)audioStemVisibility.set(audioSelectedStemLayer,true);
  const masterLayer=stemWaveforms.length?'':`<polygon class="audio-master-layer" style="fill:url(#${gradientId})" points="${[...top,...bottom].join(' ')}"></polygon>`;
  const stemLayers=stemWaveforms.map(layer=>{
    const samples=Array.isArray(layer.samples)?layer.samples.map(Number).filter(Number.isFinite):[];
    if(!samples.length)return '';
    if(!audioStemVisibility.has(layer.key))audioStemVisibility.set(layer.key,true);
    const layerTop=samples.map((value,index)=>`${samples.length===1?width/2:index/(samples.length-1)*width},${center-Math.max(1,Math.min(31,value*.31))}`);
    const layerBottom=[...samples].reverse().map((value,reverseIndex)=>`${samples.length===1?width/2:(samples.length-1-reverseIndex)/(samples.length-1)*width},${center+Math.max(1,Math.min(31,value*.31))}`);
    return `<polygon class="audio-stem-layer${audioStemVisibility.get(layer.key)?'':' hidden'}${audioSelectedStemLayer===layer.key?' selected':''}" data-stem-layer="${escapeHtml(layer.key)}" fill="${escapeHtml(layer.color||'#fff')}" points="${[...layerTop,...layerBottom].join(' ')}"></polygon>`;
  }).join('');
  const stemControls=stemWaveforms.length?`<div class="audio-stem-controls"><span>Layer stem</span>${stemWaveforms.map(layer=>{if(!audioStemVisibility.has(layer.key))audioStemVisibility.set(layer.key,true);const active=audioStemVisibility.get(layer.key),selected=audioSelectedStemLayer===layer.key,state=selected?'Selezionato':active?'Attivo':'Non attivo';return `<button type="button" class="${active?'active':''}${selected?' selected':''}" data-stem-toggle="${escapeHtml(layer.key)}" style="--stem-color:${escapeHtml(layer.color||'#fff')}" title="${state}: clic per cambiare stato"><small>${state}</small>${escapeHtml(layer.label||layer.key)}</button>`}).join('')}</div>`:`<div class="audio-stem-controls unavailable">Stem VirtualDJ non disponibili per questo brano</div>`;
  const visibleCueLayers=stemWaveforms.filter(layer=>audioStemVisibility.get(layer.key));
  const cueLaneHeight=visibleCueLayers.length*18;
  const cueLayers=visibleCueLayers.map(layer=>{const selected=audioSelectedStemLayer===layer.key,layerSections=audioLayerSections(result,layer.key,duration),markers=layerSections.slice(1).map((section,index)=>{const position=audioMusicalPosition(section.start,result),drag=selected?` data-section-boundary="${index}"`:'';return `<i class="audio-layer-cue"${drag} style="left:${Number(section.start||0)/duration*100}%;--cue-color:${escapeHtml(layer.color||'#fff')}" title="${escapeHtml(section.label||'Cue')} · battuta ${position}"><b>${escapeHtml(section.label||'Cue')} · ${position}</b></i>`}).join('');return `<div class="audio-cue-layer${selected?' selected':''}" style="--cue-color:${escapeHtml(layer.color||'#fff')}"><strong>${escapeHtml(layer.label||layer.key)}</strong>${markers}</div>`}).join('');
  const selectedSections=audioLayerSections(result,audioSelectedStemLayer,duration);
  const allowedLabels=audioStemCueLabels[audioSelectedStemLayer]||['SEZIONE'];
  const selectedStemColor=stemWaveforms.find(layer=>layer.key===audioSelectedStemLayer)?.color||'#4b9dff';
  const beatDuration=audioBeatDuration(result);
  const gridOffset=audioPhraseGridOffset(result);
  const beatGrid=[];
  if(beatDuration>0){const firstBeat=Math.floor(-gridOffset/beatDuration)-1,lastBeat=Math.ceil((duration-gridOffset)/beatDuration)+1;for(let beat=firstBeat;beat<=lastBeat;beat++){const time=gridOffset+beat*beatDuration;if(time<0||time>duration)continue;const position=((beat%16)+16)%16,type=position===0?'phrase':position%4===0?'bar':'beat';beatGrid.push(`<i class="${type}" style="left:${time/duration*100}%"></i>`)}}
  const sections=selectedSections.map((section,index)=>{const left=Number(section.start||0)/duration*100,sectionWidth=(Number(section.end||0)-Number(section.start||0))/duration*100,boundary=index<selectedSections.length-1?`<i class="audio-section-boundary" data-section-boundary="${index}" title="Trascina; tasto destro per eliminare il cue"></i>`:'',validLabel=allowedLabels.includes(section.label),options=`${validLabel?'':'<option value="" selected disabled>—</option>'}${allowedLabels.map(label=>`<option${label===section.label?' selected':''}>${label}</option>`).join('')}`,position=audioMusicalPosition(section.start,result),sectionClass=validLabel?String(section.label||'').toLowerCase().replace(/[^a-z-]/g,'-'):'unclassified',selector=index===0?'':`<select data-section-label="${index}" title="Tipo cue · battuta ${position}">${options}</select>`;return `<div class="audio-structure-segment ${sectionClass}" data-section-index="${index}" style="left:${left}%;width:${sectionWidth}%;--section-color:${escapeHtml(selectedStemColor)}">${selector}<small class="audio-section-position">${position}</small>${boundary}</div>`}).join('');
  const phraseShift=gridOffset-Number(result.grid_offset_seconds||0);
  const phraseKeyBar=(result.phrase_keys||[]).map(item=>{const start=Math.max(0,Number(item.start||0)+phraseShift),end=Math.min(duration,Number(item.end||0)+phraseShift);if(end<=start)return '';const left=start/duration*100,phraseWidth=(end-start)/duration*100,color=audioCamelotColor(item.camelot);return `<div class="audio-phrase-key-segment" style="left:${left}%;width:${phraseWidth}%;--camelot-color:${color}"><b>${escapeHtml(item.camelot||'--')}</b></div>`}).join('');
  audioStemStates.set(trackId,{selected:audioSelectedStemLayer,visibility:new Map(audioStemVisibility)});
  return `<section class="audio-overview"><div class="audio-overview-head"><div><h3>Panoramica brano <strong class="audio-overview-bpm">${Number(result.bpm||0).toFixed(2)} BPM <small>${result.bpm_source==='virtualdj'?'VDJ':''}</small></strong></h3><small>Doppio clic sulla waveform per aggiungere un cue al layer selezionato</small></div><div class="audio-overview-actions"><button type="button" class="button accent" data-export-audio-cues-vdj>Esporta cue in VDJ</button><button type="button" class="audio-preview" data-audio-track="${trackId}" data-audio-time="0" data-audio-length="${duration}" data-audio-exact="1" title="Ascolta il brano dalla panoramica">▶</button><button type="button" class="button ghost" data-audio-zoom="-1">−</button><span data-audio-zoom-label>100%</span><button type="button" class="button ghost" data-audio-zoom="1">+</button><button type="button" class="button ghost" data-audio-zoom="0">Reset</button><button type="button" class="button ghost audio-grid-drag" data-grid-drag title="Trascina per spostare la griglia; doppio clic per azzerare">↔ Griglia <small>${audioMusicalPosition(0,result)}</small></button></div></div>${stemControls}<div class="audio-overview-viewport"><div class="audio-overview-canvas" style="--cue-lanes-height:${cueLaneHeight}px;height:${148+cueLaneHeight}px" data-waveform-start="0" data-waveform-end="${duration}" data-audio-zoom-value="1"><div class="audio-cue-layers">${cueLayers}</div><div class="audio-overview-beat-grid">${beatGrid.join('')}</div><div class="audio-waveform-played"></div><svg viewBox="0 0 ${width} 76" preserveAspectRatio="none" aria-label="Waveform completa del brano"><defs><linearGradient id="${gradientId}" x1="0%" x2="100%">${stops}</linearGradient></defs><line x1="0" y1="${center}" x2="${width}" y2="${center}" class="audio-waveform-midline"></line>${masterLayer}${stemLayers}</svg><i class="audio-waveform-playhead"><b></b></i><div class="audio-structure-timeline">${sections}</div><div class="audio-phrase-key-timeline">${phraseKeyBar}</div></div></div><div class="audio-overview-times"><span>0:00</span><span>${audioPreciseTime(duration)}</span></div></section>`;
}

document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-export-audio-cues-vdj]');
  if(!button||!audioAnalysisCurrentResult)return;
  if(!window.confirm('Sostituire tutti i cue 1-16 del brano nel deck VirtualDJ?'))return;
  button.disabled=true;
  try{
    const result=await post('audio-analysis-export-vdj',{id:Number(audioAnalysisCurrentResult.track_id||0)});
    toast(`${result.count} cue esportati nel deck ${result.deck}`);
  }catch(error){toast(error.message)}finally{button.disabled=false}
});

document.addEventListener('click',async event=>{
  const autoButton=event.target.closest('[data-auto-audio-cues]');
  const resetButton=event.target.closest('[data-reset-audio-cues]');
  const button=autoButton||resetButton;
  if(!button||!audioAnalysisCurrentResult)return;
  if(resetButton&&!window.confirm('Eliminare tutti i cue locali di tutti gli stem?'))return;
  button.disabled=true;
  try{
    const action=autoButton?'audio-analysis-auto-cues':'audio-analysis-reset-cues';
    const result=await post(action,{id:Number(audioAnalysisCurrentResult.track_id||0)});
    renderAudioAnalysis(result,audioAnalysisTracks.get(Number(result.track_id||0)));
    toast(autoButton?'Riconoscimento automatico completato':'Tutti i cue locali sono stati eliminati');
  }catch(error){toast(error.message)}finally{button.disabled=false}
});

function audioCamelotColor(value){
  const match=String(value||'').toUpperCase().match(/^(\d{1,2})([AB])$/);
  if(!match)return '#26384b';
  const hues={1:120,2:150,3:180,4:210,5:240,6:270,7:300,8:330,9:0,10:30,11:60,12:90};
  const hue=hues[Number(match[1])]??210;
  return `hsl(${hue} 68% ${match[2]==='A'?34:46}%)`;
}

function camelotNumericDifference(leftValue,rightValue){
  const left=Number(String(leftValue||'').match(/^(\d{1,2})/)?.[1]||0);
  const right=Number(String(rightValue||'').match(/^(\d{1,2})/)?.[1]||0);
  if(!left||!right)return null;
  const difference=Math.abs(left-right);
  return Math.min(difference,12-difference);
}

function updateAudioKeyCompatibility(){
  const primary=[...$('#audio-analysis-result')?.querySelectorAll('.audio-phrase-key-segment')||[]];
  const secondary=[...$('#audio-analysis-result-2')?.querySelectorAll('.audio-phrase-key-segment')||[]];
  if(!primary.length||!secondary.length)return;
  const primaryItems=primary.map(element=>({element,rect:element.getBoundingClientRect(),key:element.textContent.trim()}));
  secondary.forEach(element=>{
    const rect=element.getBoundingClientRect();
    const center=rect.left+rect.width/2;
    const match=primaryItems.find(item=>center>=item.rect.left&&center<=item.rect.right)||primaryItems.reduce((best,item)=>Math.abs((item.rect.left+item.rect.width/2)-center)<Math.abs((best.rect.left+best.rect.width/2)-center)?item:best);
    const key=element.textContent.trim();
    const difference=camelotNumericDifference(match?.key,key);
    element.classList.remove('key-diff-0','key-diff-1','key-diff-2','key-diff-6','key-diff-bad');
    if(difference===0)element.classList.add('key-diff-0');
    else if(difference===1)element.classList.add('key-diff-1');
    else if(difference===2)element.classList.add('key-diff-2');
    else if(difference===6)element.classList.add('key-diff-6');
    else if(difference!==null)element.classList.add('key-diff-bad');
    element.title=difference===null?'Key non confrontabile':`${match.key} ↔ ${key} · differenza ${difference}`;
  });
}

function scheduleAudioKeyCompatibility(){
  requestAnimationFrame(updateAudioKeyCompatibility);
}

function audioComparisonTable(result){
  const spotifyEnergy=result.spotify_energy===null||result.spotify_energy===undefined?null:Math.round(Number(result.spotify_energy)*100);
  const dbEnergy=result.kr_energy??(result.db_energy?Number(result.db_energy)*20:null);
  const rows=[
    ['Durata',audioTime(result.duration_seconds),audioTime(result.db_duration),'--',audioDifference(result.duration_seconds,result.db_duration,' s')],
    ['BPM',audioCompareValue(result.bpm),audioCompareValue(result.db_bpm),audioCompareValue(result.spotify_tempo),audioDifference(result.bpm,result.db_bpm)],
    ['Tonalita',`${result.musical_key||'--'} · ${result.camelot||'--'}`,`${result.db_musical_key||'--'} · ${result.db_camelot||'--'}`,'--','--'],
    ['Energia',audioCompareValue(result.energy_score,'/100'),audioCompareValue(dbEnergy,'/100'),audioCompareValue(spotifyEnergy,'/100'),audioDifference(result.energy_score,dbEnergy)],
    ['Loudness',audioCompareValue(result.integrated_lufs,' LUFS'),'--',audioCompareValue(result.spotify_loudness,' dB'),audioDifference(result.integrated_lufs,result.spotify_loudness,' dB')],
  ];
  return `<section class="audio-compare-section"><h3>Confronto dati</h3><div class="audio-compare-scroll"><table class="audio-compare-table"><thead><tr><th>Metrica</th><th>Analisi locale</th><th>DB / VirtualDJ</th><th>Spotify</th><th>Differenza locale / DB</th></tr></thead><tbody>${rows.map(row=>`<tr>${row.map((value,index)=>`<${index?'td':'th'}>${escapeHtml(value)}</${index?'td':'th'}>`).join('')}</tr>`).join('')}</tbody></table></div></section>`;
}

function renderAudioAnalysis(result,track={}){
  const target=$('#audio-analysis-result');
  if(!target)return;
  const requestedTrackId=Number(result?.track_id||track.id||0);
  const panelViewState=captureAudioPanelView(target)||audioPanelViewStates.get(requestedTrackId)||null;
  target.classList.remove('hidden');
  const resultTrackId=Number(result?.track_id||track.id||0);
  target.dataset.trackId=String(resultTrackId);
  if(resultTrackId>0)audioAnalysisResults.set(resultTrackId,result);
  audioAnalysisCurrentResult=result&&result.status!=='not_analyzed'&&result.status!=='error'?result:null;
  if(!result||result.status==='not_analyzed'){
    target.innerHTML=`<div class="audio-result-head"><div><span class="kicker">DA ANALIZZARE</span><h2>${escapeHtml(track.artist||'')} - ${escapeHtml(track.title||'')}</h2><small>${escapeHtml(track.file_path||'')}</small></div></div><div class="empty-state">Nessuna analisi locale salvata per questo brano.</div>`;
    return;
  }
  if(result.status==='error'){
    target.innerHTML=`<div class="audio-result-head"><div><span class="kicker">ERRORE ANALISI</span><h2>${escapeHtml(result.artist||track.artist||'')} - ${escapeHtml(result.title||track.title||'')}</h2></div></div><div class="empty-state">${escapeHtml(result.error_message||'Analisi non riuscita')}</div>`;
    return;
  }
  const trackId=Number(result.track_id||track.id||0);
  target.innerHTML=`<div class="audio-analysis-slot-title">ANALISI 1 · ${escapeHtml(result.artist||track.artist||'')} - ${escapeHtml(result.title||track.title||'')}</div>`+audioOverview(result,trackId);
  let sectionTimeline=target.querySelector('.audio-structure-timeline');
  if(sectionTimeline&&!sectionTimeline.children.length)sectionTimeline.innerHTML='<div class="audio-structure-placeholder">Nessuna sezione</div>';
  target.querySelector('.audio-overview-actions')?.insertAdjacentHTML('afterbegin','<button type="button" class="button accent" data-sync-audio-analysis>SYNC BPM</button><button type="button" class="button primary" data-auto-audio-cues>Riconoscimento automatico</button><button type="button" class="button ghost" data-reset-audio-cues>Reset cue</button>');
  restoreAudioPanelView(target,panelViewState);
  refreshAudioTempoSyncButton();
}

function renderAudioAnalysisSecondary(result,track={}){
  const target=$('#audio-analysis-result-2');
  if(!target)return;
  const requestedTrackId=Number(result?.track_id||track.id||0);
  const panelViewState=captureAudioPanelView(target)||audioPanelViewStates.get(requestedTrackId)||null;
  target.classList.remove('hidden');
  const trackId=Number(result?.track_id||track.id||0);
  target.dataset.trackId=String(trackId);
  if(trackId>0)audioAnalysisResults.set(trackId,result);
  if(!result||result.status==='not_analyzed'||result.status==='error'){
    target.innerHTML=`<div class="audio-analysis-slot-title">ANALISI 2 · ${escapeHtml(track.artist||'')} - ${escapeHtml(track.title||'')}</div><div class="empty-state">Analisi non disponibile per il confronto.</div>`;
    target.querySelector('.empty-state')?.insertAdjacentHTML('beforeend',`<br><button type="button" class="button primary" data-run-audio-slot="2" data-audio-track="${trackId}">Analizza brano 2</button>`);
    return;
  }
  const savedTrackId=audioSelectedStemTrackId,savedLayer=audioSelectedStemLayer,savedVisibility=new Map(audioStemVisibility);
  const overview=audioOverview(result,Number(result.track_id||track.id||0));
  audioSelectedStemTrackId=savedTrackId;audioSelectedStemLayer=savedLayer;audioStemVisibility.clear();savedVisibility.forEach((value,key)=>audioStemVisibility.set(key,value));
  target.innerHTML=`<div class="audio-analysis-slot-title">ANALISI 2 · ${escapeHtml(result.artist||track.artist||'')} - ${escapeHtml(result.title||track.title||'')}</div>`+overview;
  let sectionTimeline=target.querySelector('.audio-structure-timeline');
  if(sectionTimeline&&!sectionTimeline.children.length)sectionTimeline.innerHTML='<div class="audio-structure-placeholder">Nessuna sezione</div>';
  target.querySelector('.audio-overview-actions')?.insertAdjacentHTML('afterbegin','<button type="button" class="button primary" data-send-transition-automix>Invia ad Automix</button><button type="button" class="button accent" data-preview-transition>▶ Transizione</button><button type="button" class="button accent audio-track-shift" data-track-shift>↔ Traccia <small data-track-shift-label>0 battute</small></button><button type="button" class="button primary" data-auto-audio-cues>Riconoscimento automatico</button><button type="button" class="button ghost" data-reset-audio-cues>Reset cue</button>');
  restoreAudioPanelView(target,panelViewState);
  refreshAudioTempoSyncButton();
  scheduleAudioKeyCompatibility();
  scheduleAudioTransitionHighlight();
}

function captureAudioPanelView(panel){
  const viewport=panel?.querySelector('.audio-overview-viewport');
  const canvas=panel?.querySelector('.audio-overview-canvas');
  if(!viewport||!canvas)return null;
  const state={
    zoom:Number(canvas.dataset.audioZoomValue||1),
    scrollLeft:Number(viewport.scrollLeft||0),
    trackShiftBeats:canvas.dataset.trackShiftBeats||'0',
    trackSyncBaseBeats:canvas.dataset.trackSyncBaseBeats||'0',
    audioPixelsPerBeat:canvas.dataset.audioPixelsPerBeat||'',
    audioBeatCount:canvas.dataset.audioBeatCount||'',
    audioTempoScale:canvas.dataset.audioTempoScale||'1',
  };
  const trackId=Number(panel?.dataset.trackId||0);
  if(trackId>0)audioPanelViewStates.set(trackId,state);
  return state;
}

function restoreAudioPanelView(panel,state){
  const overview=panel?.querySelector('.audio-overview');
  const viewport=overview?.querySelector('.audio-overview-viewport');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  if(!overview||!viewport||!canvas){return}
  if(state){
    canvas.dataset.trackShiftBeats=state.trackShiftBeats;
    canvas.dataset.trackSyncBaseBeats=state.trackSyncBaseBeats;
    canvas.dataset.audioTempoScale=state.audioTempoScale;
    if(state.audioPixelsPerBeat)canvas.dataset.audioPixelsPerBeat=state.audioPixelsPerBeat;
    if(state.audioBeatCount)canvas.dataset.audioBeatCount=state.audioBeatCount;
    setAudioOverviewZoom(overview,state.zoom);
    updateAudioCanvasTailSpaces();
    requestAnimationFrame(()=>{viewport.scrollLeft=state.scrollLeft;scheduleAudioKeyCompatibility()});
  }else updateAudioCanvasTailSpaces();
  const trackId=Number(panel?.dataset.trackId||0);
  if(trackId>0)audioPanelViewStates.set(trackId,captureAudioPanelView(panel));
}

async function runAudioAnalysis(){
  const trackId=Number($('#audio-analysis-track')?.value||0);
  if(!trackId)return;
  const button=$('#audio-analysis-run');
  button.disabled=true;
  button.textContent='Analisi in corso...';
  krProgress.start('Analisi audio locale',0,'Il brano viene letto da Python e FFmpeg');
  try{
    const result=await post('audio-analysis-run',{id:trackId});
    const track=audioAnalysisTracks.get(trackId);
    if(audioAnalysisRunTargetSlot===2)renderAudioAnalysisSecondary(result,track);
    else renderAudioAnalysis(result,track);
    if(track)track.analysis_status='complete';
    document.dispatchEvent(new CustomEvent('audio-analysis-completed',{detail:{trackId}}));
    krProgress.done('Analisi audio completata',`${result.build_ups?.length||0} build-up · ${result.drops?.length||0} drop`);
    toast('Analisi locale salvata nel file JSON indipendente');
  }catch(error){
    krProgress.fail('Analisi audio fallita',error.message);
    toast(error.message);
  }finally{
    button.disabled=false;
    button.textContent='Analizza brano selezionato';
  }
}

window.loadAudioAnalysisPage=async function(){
  if(audioAnalysisLoaded)return;
  audioAnalysisLoaded=true;
};

window.openAudioAnalysisTrack=async function(track,options={}){
  const openRequest=++audioAnalysisOpenRequest;
  const trackId=Number(track?.id||0);
  if(trackId<1)throw new Error('Record libreria non disponibile per l’analisi audio.');
  stopAudioAnalysisPreview();
  stopAudioComparisonPreview();
  stopAudioTransitionPreview();
  showView('audio-analysis');
  const primary=$('#audio-analysis-result');
  const secondary=$('#audio-analysis-result-2');
  const nextTrack=options.source==='playlist'&&Number(options.nextTrack?.id||0)>0?options.nextTrack:null;
  audioAnalysisTracks.clear();
  audioAnalysisResults.clear();
  audioAnalysisTracks.set(trackId,track);
  if(nextTrack)audioAnalysisTracks.set(Number(nextTrack.id),nextTrack);
  audioAnalysisCurrentResult=null;
  audioAutomixPoints=null;
  audioAutomixPoiDrag=null;
  audioAnalysisActivePanelId='audio-analysis-result';
  audioAnalysisRunTargetSlot=1;
  [primary,secondary].forEach(panel=>{
    if(!panel)return;
    panel.innerHTML='';
    panel.classList.add('hidden');
    delete panel.dataset.trackId;
    delete panel.dataset.playlistTrackId;
  });
  $('#view-audio-analysis')?.classList.remove('audio-comparison-mode','audio-tempo-synced');
  const select=$('#audio-analysis-track');
  if(select){
    select.innerHTML=`<option value="${trackId}">${escapeHtml(track.artist||'')} - ${escapeHtml(track.title||'')}</option>`;
    select.value=String(trackId);
  }
  $('#audio-analysis-search').value=`${track.artist||''} - ${track.title||''}`;
  $('#audio-analysis-selection').textContent=`${track.artist||''} - ${track.title||''}`;
  $('#audio-analysis-run').disabled=false;
  $('#audio-track-overlay')?.classList.add('hidden');
  if(primary&&options.source==='playlist')primary.dataset.playlistTrackId=String(trackId);
  if(nextTrack&&secondary){
    const nextTrackId=Number(nextTrack.id);
    $('#view-audio-analysis')?.classList.add('audio-comparison-mode');
    secondary.dataset.playlistTrackId=String(nextTrackId);
    const [primaryResult,secondaryResult]=await Promise.all([
      api(`audio-analysis-result&id=${trackId}`),
      api(`audio-analysis-result&id=${nextTrackId}`)
    ]);
    if(openRequest!==audioAnalysisOpenRequest)return;
    renderAudioAnalysis(primaryResult,track);
    renderAudioAnalysisSecondary(secondaryResult,nextTrack);
    return;
  }
  const result=await api(`audio-analysis-result&id=${trackId}`);
  if(openRequest!==audioAnalysisOpenRequest)return;
  renderAudioAnalysis(result,track);
};

document.addEventListener('click',event=>{
  const button=event.target.closest('[data-run-audio-slot]');
  if(!button)return;
  const trackId=Number(button.dataset.audioTrack||0);
  const track=audioAnalysisTracks.get(trackId);
  if(!trackId||!track)return;
  audioAnalysisRunTargetSlot=Number(button.dataset.runAudioSlot||1);
  const select=$('#audio-analysis-track');
  if(select){
    select.innerHTML=`<option value="${trackId}">${escapeHtml(track.artist||'')} - ${escapeHtml(track.title||'')}</option>`;
    select.value=String(trackId);
  }
  runAudioAnalysis();
});

window.refreshLibraryAudioAnalysisStatuses=async function(){
  const ids=[...new Set(state.tracks.map(track=>Number(track.id)).filter(id=>id>0))];
  if(!ids.length)return;
  const data=await post('audio-analysis-statuses',{ids});
  $$('#library-results .library-audio-analysis').forEach(button=>button.classList.toggle('analyzed',Boolean(data.items?.[String(button.dataset.trackId)]?.exists)));
};

document.addEventListener('click',event=>{
  const button=event.target.closest('.library-audio-analysis');
  if(!button)return;
  event.preventDefault();
  event.stopPropagation();
  const track=state.tracks.find(item=>Number(item.id)===Number(button.dataset.trackId));
  if(!track){toast('Record libreria non disponibile per l’analisi audio');return}
  window.openAudioAnalysisTrack(track).catch(error=>toast(error.message));
});

document.addEventListener('audio-analysis-completed',event=>{
  const trackId=Number(event.detail?.trackId||0);
  if(trackId>0)$$(`#library-results .library-audio-analysis[data-track-id="${trackId}"]`).forEach(button=>button.classList.add('analyzed'));
});

$('#audio-analysis-search-button')?.addEventListener('click',()=>searchAudioAnalysisTracks().catch(error=>toast(error.message)));
$('#audio-analysis-search')?.addEventListener('focus',()=>{
  const select=$('#audio-analysis-track');
  if(select?.options.length>1)$('#audio-track-overlay')?.classList.remove('hidden');
});
$('#audio-analysis-search')?.addEventListener('input',()=>{
  clearTimeout(audioAnalysisSearchTimer);
  audioAnalysisSearchTimer=setTimeout(()=>searchAudioAnalysisTracks().catch(error=>toast(error.message)),350);
});
$('#audio-analysis-track')?.addEventListener('change',()=>selectAudioAnalysisTrack().catch(error=>toast(error.message)));
$('#audio-analysis-run')?.addEventListener('click',runAudioAnalysis);
$('#audio-analysis-search')?.addEventListener('keydown',event=>{
  if(event.key==='Escape')$('#audio-track-overlay')?.classList.add('hidden');
  if(event.key==='Enter'){event.preventDefault();searchAudioAnalysisTracks().catch(error=>toast(error.message))}
});
document.addEventListener('click',event=>{
  if(!event.target.closest('.audio-track-search'))$('#audio-track-overlay')?.classList.add('hidden');
});

function stopAudioAnalysisPreview(){
  clearTimeout(audioAnalysisPreviewTimer);
  cancelAnimationFrame(audioAnalysisPreviewFrame);
  if(audioAnalysisPreview){audioAnalysisPreview.pause();audioAnalysisPreview.src='';audioAnalysisPreview=null}
  if(audioAnalysisPreviewWaveform){audioAnalysisPreviewWaveform.style.setProperty('--playhead','0%');audioAnalysisPreviewWaveform.classList.remove('is-playing');audioAnalysisPreviewWaveform=null}
  if(audioAnalysisPreviewButton){audioAnalysisPreviewButton.textContent='▶';audioAnalysisPreviewButton.classList.remove('is-playing');audioAnalysisPreviewButton=null}
}

function holdAudioAnalysisPreview(){
  if(!audioAnalysisPreview||!audioAnalysisPreviewWaveform||!audioAnalysisPreviewButton)return;
  const time=Number(audioAnalysisPreview.currentTime||0);
  const trackId=Number(audioAnalysisCurrentResult?.track_id||0);
  const waveform=audioAnalysisPreviewWaveform;
  const button=audioAnalysisPreviewButton;
  clearTimeout(audioAnalysisPreviewTimer);
  cancelAnimationFrame(audioAnalysisPreviewFrame);
  audioAnalysisPreview.pause();
  audioAnalysisPreview.src='';
  audioAnalysisStoppedPosition={trackId,time,start:audioAnalysisPreviewStart,end:audioAnalysisPreviewEnd};
  audioAnalysisPreview=null;
  audioAnalysisPreviewWaveform=null;
  audioAnalysisPreviewButton=null;
  waveform.classList.remove('is-playing');
  waveform.classList.add('is-paused');
  button.textContent='▶';
  button.classList.remove('is-playing');
}

function pauseAudioAnalysisPreview(){
  if(!audioAnalysisPreview)return;
  clearTimeout(audioAnalysisPreviewTimer);
  cancelAnimationFrame(audioAnalysisPreviewFrame);
  audioAnalysisPreview.pause();
  if(audioAnalysisPreviewButton){audioAnalysisPreviewButton.textContent='▶';audioAnalysisPreviewButton.classList.remove('is-playing')}
  updateAudioAnalysisPlayhead();
  cancelAnimationFrame(audioAnalysisPreviewFrame);
}

function continueAudioAnalysisPreview(){
  if(!audioAnalysisPreview)return;
  audioAnalysisPreview.play().then(()=>{
    if(audioAnalysisPreviewButton){audioAnalysisPreviewButton.textContent='■';audioAnalysisPreviewButton.classList.add('is-playing')}
    updateAudioAnalysisPlayhead();
    clearTimeout(audioAnalysisPreviewTimer);
    audioAnalysisPreviewTimer=setTimeout(stopAudioAnalysisPreview,Math.max(.1,audioAnalysisPreviewEnd-audioAnalysisPreview.currentTime)*1000);
  }).catch(error=>toast(error.message));
}

function seekAudioAnalysisPreview(targetTime){
  if(!audioAnalysisPreview||!audioAnalysisPreviewWaveform)return;
  const target=Math.max(audioAnalysisPreviewStart,Math.min(audioAnalysisPreviewEnd,targetTime));
  const duration=Math.max(.01,audioAnalysisPreviewEnd-audioAnalysisPreviewStart);
  const progress=Math.max(0,Math.min(100,(target-audioAnalysisPreviewStart)/duration*100));
  audioAnalysisPreviewWaveform.style.setProperty('--playhead',`${progress}%`);
  const playhead=audioAnalysisPreviewWaveform.querySelector('.audio-waveform-playhead');
  if(playhead)playhead.dataset.musicalPosition=audioMusicalPosition(target,audioAnalysisCurrentResult);
  if(typeof audioAnalysisPreview.fastSeek==='function')audioAnalysisPreview.fastSeek(target);
  else audioAnalysisPreview.currentTime=target;
  cancelAnimationFrame(audioAnalysisPreviewFrame);
  if(!audioAnalysisPreview.paused){
    updateAudioAnalysisPlayhead();
    clearTimeout(audioAnalysisPreviewTimer);
    audioAnalysisPreviewTimer=setTimeout(stopAudioAnalysisPreview,Math.max(.1,audioAnalysisPreviewEnd-target)*1000);
  }
}

function updateAudioAnalysisPlayhead(){
  if(!audioAnalysisPreview||!audioAnalysisPreviewWaveform)return;
  const duration=Math.max(.01,audioAnalysisPreviewEnd-audioAnalysisPreviewStart);
  const progress=Math.max(0,Math.min(100,(audioAnalysisPreview.currentTime-audioAnalysisPreviewStart)/duration*100));
  audioAnalysisPreviewWaveform.style.setProperty('--playhead',`${progress}%`);
  const playhead=audioAnalysisPreviewWaveform.querySelector('.audio-waveform-playhead');
  if(playhead)playhead.dataset.musicalPosition=audioMusicalPosition(audioAnalysisPreview.currentTime,audioAnalysisCurrentResult);
  audioAnalysisPreviewFrame=requestAnimationFrame(updateAudioAnalysisPlayhead);
}

function audioAnalysisStreamUrl(trackId){
  const availableStems=(audioAnalysisCurrentResult?.stem_waveforms||[]).map(layer=>layer.key);
  const activeStems=availableStems.filter(key=>audioStemVisibility.get(key)||key===audioSelectedStemLayer);
  if(availableStems.length&&!activeStems.length)return '';
  const stemQuery=activeStems.length?`&stems=${encodeURIComponent(activeStems.join(','))}`:'';
  return `api.php?action=audio-analysis-stream&id=${trackId}${stemQuery}`;
}

function audioAnalysisStreamUrlForTrack(trackId){
  const result=audioAnalysisResults.get(trackId);
  const state=audioStemStates.get(trackId);
  const available=(result?.stem_waveforms||[]).map(layer=>layer.key);
  const active=available.filter(key=>state?.visibility?.get(key)||key===state?.selected);
  if(available.length&&!active.length)return '';
  return `api.php?action=audio-analysis-stream&id=${trackId}${active.length?`&stems=${encodeURIComponent(active.join(','))}`:''}`;
}

function stopAudioComparisonPreview(){
  audioComparisonPreviews=[];
  audioTempoSyncPair=null;
  audioAutomixPoints=null;
  $('#view-audio-analysis')?.classList.remove('audio-tempo-synced');
  $$('.audio-overview').forEach(overview=>{
    const canvas=overview.querySelector('.audio-overview-canvas');
    if(!canvas)return;
    canvas.dataset.audioTempoScale='1';
    canvas.dataset.trackSyncBaseBeats='0';
    delete canvas.dataset.audioPixelsPerBeat;
    delete canvas.dataset.audioBeatCount;
    canvas.style.marginRight='0px';
    const zoom=Number(canvas.dataset.audioZoomValue||1);
    canvas.style.width=`${zoom*100}%`;
  });
  updateAudioCanvasTailSpaces();
  $$('[data-sync-audio-analysis]').forEach(button=>{button.textContent='SYNC BPM';button.classList.remove('is-playing')});
}

function refreshAudioTempoSyncButton(){
  const button=$('[data-sync-audio-analysis]');
  if(!button)return;
  const primaryTrackId=Number($('#audio-analysis-result')?.dataset.trackId||0);
  const secondaryTrackId=Number($('#audio-analysis-result-2')?.dataset.trackId||0);
  const active=$('#view-audio-analysis')?.classList.contains('audio-tempo-synced')
    &&audioTempoSyncPair
    &&audioTempoSyncPair.primaryTrackId===primaryTrackId
    &&audioTempoSyncPair.secondaryTrackId===secondaryTrackId;
  if(active){
    button.textContent=`SYNC ${audioTempoSyncPair.secondaryBpm.toFixed(2)} → ${audioTempoSyncPair.primaryBpm.toFixed(2)} BPM`;
    button.classList.add('is-playing');
    return;
  }
  button.textContent='SYNC BPM';
  button.classList.remove('is-playing');
}

function activateAudioAnalysisPanel(panel){
  if(!panel)return;
  audioAnalysisActivePanelId=panel.id;
  const trackId=Number(panel.dataset.trackId||0);
  const result=audioAnalysisResults.get(trackId);
  if(!result)return;
  audioAnalysisCurrentResult=result;
  audioSelectedStemTrackId=trackId;
  const state=audioStemStates.get(trackId);
  audioStemVisibility.clear();
  if(state?.visibility)state.visibility.forEach((value,key)=>audioStemVisibility.set(key,value));
  audioSelectedStemLayer=state?.selected||'';
}

document.addEventListener('pointerdown',event=>{
  activateAudioAnalysisPanel(event.target.closest('#audio-analysis-result,#audio-analysis-result-2'));
},true);

function resumeAudioAnalysisPreview(snapshot){
  const trackId=Number(audioAnalysisCurrentResult?.track_id||$('#audio-analysis-track')?.value||0);
  const url=audioAnalysisStreamUrl(trackId);
  const overview=$(`#${audioAnalysisActivePanelId} .audio-overview`);
  const button=overview?.querySelector('.audio-preview');
  const waveform=overview?.querySelector('.audio-overview-canvas');
  if(!url||!button||!waveform)return;
  const audio=new Audio(url);
  audioAnalysisPreview=audio;
  audioAnalysisPreviewButton=button;
  audioAnalysisPreviewWaveform=waveform;
  audioAnalysisPreviewStart=snapshot.start;
  audioAnalysisPreviewEnd=snapshot.end;
  waveform.classList.add('is-playing');
  button.textContent='■';button.classList.add('is-playing');
  audio.addEventListener('loadedmetadata',()=>{
    audio.currentTime=Math.max(snapshot.start,Math.min(snapshot.currentTime,audio.duration||snapshot.end));
    audio.play().then(()=>{
      updateAudioAnalysisPlayhead();
      audioAnalysisPreviewTimer=setTimeout(stopAudioAnalysisPreview,Math.max(.1,snapshot.end-audio.currentTime)*1000);
    }).catch(error=>{stopAudioAnalysisPreview();toast(error.message)});
  },{once:true});
  audio.addEventListener('ended',stopAudioAnalysisPreview,{once:true});
  audio.addEventListener('error',()=>{stopAudioAnalysisPreview();toast('Preascolto stem non disponibile')},{once:true});
}

document.addEventListener('click',event=>{
  const button=event.target.closest('.audio-preview');
  if(!button)return;
  event.preventDefault();
  stopAudioTransitionPreview();
  if(button===audioAnalysisPreviewButton){holdAudioAnalysisPreview();return}
  const trackId=Number(button.dataset.audioTrack||0);
  const stopped=audioAnalysisStoppedPosition?.trackId===trackId?audioAnalysisStoppedPosition:null;
  stopAudioAnalysisPreview();
  const cueTime=Number(button.dataset.audioTime||0);
  const previewLength=Number(button.dataset.audioLength||12);
  if(!trackId)return;
  const streamUrl=audioAnalysisStreamUrl(trackId);
  if(!streamUrl){toast('Attiva o seleziona almeno uno stem da riprodurre');return}
  const audio=new Audio(streamUrl);
  audio.preload='auto';
  audioAnalysisPreview=audio;
  audioAnalysisPreviewButton=button;
  audioAnalysisPreviewWaveform=button.closest('.audio-cue')?.querySelector('.audio-waveform-wrap')||button.closest('.audio-overview')?.querySelector('.audio-overview-canvas')||null;
  audioAnalysisPreviewStart=Math.max(0,cueTime-(button.dataset.audioExact==='1'||button.closest('.audio-cue')?.classList.contains('loop')?0:3));
  audioAnalysisPreviewEnd=audioAnalysisPreviewStart+Math.max(4,previewLength);
  const initialTime=stopped?Math.max(audioAnalysisPreviewStart,Math.min(audioAnalysisPreviewEnd,stopped.time)):audioAnalysisPreviewStart;
  audioAnalysisStoppedPosition=null;
  audioAnalysisPreviewWaveform?.classList.remove('is-paused');
  audioAnalysisPreviewWaveform?.classList.add('is-playing');
  button.textContent='■';
  button.classList.add('is-playing');
  audio.addEventListener('loadedmetadata',()=>{
    audio.currentTime=initialTime;
    audio.play().then(()=>{
      updateAudioAnalysisPlayhead();
      audioAnalysisPreviewTimer=setTimeout(stopAudioAnalysisPreview,Math.max(.1,audioAnalysisPreviewEnd-initialTime)*1000);
    }).catch(error=>{stopAudioAnalysisPreview();toast(error.message)});
  },{once:true});
  audio.addEventListener('ended',stopAudioAnalysisPreview,{once:true});
  audio.addEventListener('error',()=>{stopAudioAnalysisPreview();toast('Preascolto audio non disponibile')},{once:true});
});

document.addEventListener('click',async event=>{
  const save=event.target.closest('.audio-drop-save');
  const remove=event.target.closest('.audio-drop-delete');
  const add=event.target.closest('.audio-drop-add');
  if(!save&&!remove&&!add)return;
  const trackId=Number((save||remove||add).dataset.audioTrack||0);
  if(!trackId)return;
  try{
    let payload;
    if(add){
      const start=parseAudioTime(window.prompt('Inizio cue point (mm:ss,xx)','0:00,00'));
      if(!Number.isFinite(start))return;
      const end=parseAudioTime(window.prompt('Fine cue point (mm:ss,xx)',audioPreciseTime(start+7.4)));
      if(!Number.isFinite(end))return;
      payload={id:trackId,index:-1,start,end};
    }else{
      const row=(save||remove).closest('.audio-cue');
      const index=Number((save||remove).dataset.dropIndex);
      if(remove){
        if(!window.confirm('Eliminare questo cue point dal JSON di analisi?'))return;
        payload={id:trackId,index,delete:true};
      }else{
        payload={id:trackId,index,start:parseAudioTime(row.querySelector('[data-drop-start]').value),end:parseAudioTime(row.querySelector('[data-drop-end]').value)};
      }
    }
    const result=await post('audio-analysis-drop-update',payload);
    renderAudioAnalysis(result,audioAnalysisTracks.get(trackId));
    toast(remove?'Cue point eliminato dal JSON':'Cue point manuale salvato nel JSON');
  }catch(error){toast(error.message)}
});

document.addEventListener('click',event=>{
  const expand=event.target.closest('.audio-drop-expand');
  if(!expand)return;
  const row=expand.closest('.audio-cue.drop');
  const opening=!row.classList.contains('audio-drop-expanded');
  $$('.audio-drop-expanded').forEach(item=>item.classList.remove('audio-drop-expanded'));
  row.classList.toggle('audio-drop-expanded',opening);
  document.body.classList.toggle('audio-drop-editor-open',opening);
  expand.textContent=opening?'Riduci':'Espandi';
});

document.addEventListener('keydown',event=>{
  if(event.key!=='Escape')return;
  const expanded=$('.audio-drop-expanded');
  if(!expanded)return;
  expanded.classList.remove('audio-drop-expanded');
  expanded.querySelector('.audio-drop-expand').textContent='Espandi';
  document.body.classList.remove('audio-drop-editor-open');
});

document.addEventListener('click',event=>{
  const waveform=event.target.closest('.audio-waveform-wrap,.audio-overview-canvas');
  if(!waveform||event.target.closest('[data-drop-handle],[data-section-boundary],[data-overview-cue],[data-section-label]')||waveform!==audioAnalysisPreviewWaveform||!audioAnalysisPreview)return;
  const bounds=waveform.getBoundingClientRect();
  const ratio=Math.max(0,Math.min(1,(event.clientX-bounds.left)/Math.max(1,bounds.width)));
  seekAudioAnalysisPreview(audioAnalysisPreviewStart+(audioAnalysisPreviewEnd-audioAnalysisPreviewStart)*ratio);
});

function audioOverviewLayoutPayload(){
  const result=audioAnalysisCurrentResult||{};
  const layerSections={};
  Object.entries(result.layer_sections||{}).forEach(([key,items])=>{layerSections[key]=(items||[]).map(item=>({start:Number(item.start||0),end:Number(item.end||0),label:String(item.label||'Sezione')}))});
  return {
    id:Number(result.track_id||$('#audio-analysis-track')?.value||0),
    sections:(result.sections||[]).map(item=>({start:Number(item.start||0),end:Number(item.end||0),label:String(item.label||'Sezione')})),
    build_ups:(result.build_ups||[]).map(item=>({start:Number(item.start||0),end:Number(item.end||0),name:String(item.name||'Build-up')})),
    drops:(result.drops||[]).map(item=>({start:Number(item.start||0),end:Number(item.end||0),name:String(item.name||'Drop')})),
    layer_sections:layerSections,
    selected_layer:audioSelectedStemLayer,
    grid_offset:Number(result.grid_offset_seconds||0),
  };
}

function captureAudioOverviewView(){
  const overview=$(`#${audioAnalysisActivePanelId} .audio-overview`);
  const viewport=overview?.querySelector('.audio-overview-viewport');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  return {
    zoom:Number(canvas?.dataset.audioZoomValue||1),
    scrollLeft:Number(viewport?.scrollLeft||0),
    playing:Boolean(audioAnalysisPreview&&canvas===audioAnalysisPreviewWaveform),
  };
}

function restoreAudioOverviewView(state){
  const overview=$(`#${audioAnalysisActivePanelId} .audio-overview`);
  const viewport=overview?.querySelector('.audio-overview-viewport');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  if(!overview||!viewport||!canvas)return;
  setAudioOverviewZoom(overview,state.zoom||1);
  viewport.scrollLeft=Math.max(0,Math.min(state.scrollLeft||0,viewport.scrollWidth-viewport.clientWidth));
  if(state.playing&&audioAnalysisPreview){
    cancelAnimationFrame(audioAnalysisPreviewFrame);
    audioAnalysisPreviewWaveform=canvas;
    audioAnalysisPreviewButton=overview.querySelector('.audio-preview');
    canvas.classList.add('is-playing');
    if(audioAnalysisPreviewButton){audioAnalysisPreviewButton.textContent='■';audioAnalysisPreviewButton.classList.add('is-playing')}
    updateAudioAnalysisPlayhead();
  }
  const stopped=audioAnalysisStoppedPosition;
  if(stopped&&stopped.trackId===Number(audioAnalysisCurrentResult?.track_id||0)){
    const duration=Math.max(.01,stopped.end-stopped.start);
    const progress=Math.max(0,Math.min(100,(stopped.time-stopped.start)/duration*100));
    canvas.style.setProperty('--playhead',`${progress}%`);
    const playhead=canvas.querySelector('.audio-waveform-playhead');
    if(playhead)playhead.dataset.musicalPosition=audioMusicalPosition(stopped.time,audioAnalysisCurrentResult);
    canvas.classList.add('is-paused');
  }
}

async function saveAudioOverviewLayout(){
  const payload=audioOverviewLayoutPayload();
  if(payload.id<1)return;
  const viewState=captureAudioOverviewView();
  audioAnalysisCurrentResult=await post('audio-analysis-layout-update',payload);
  audioAnalysisResults.set(payload.id,audioAnalysisCurrentResult);
  if(audioAnalysisActivePanelId==='audio-analysis-result-2')renderAudioAnalysisSecondary(audioAnalysisCurrentResult,audioAnalysisTracks.get(payload.id));
  else renderAudioAnalysis(audioAnalysisCurrentResult,audioAnalysisTracks.get(payload.id));
  restoreAudioOverviewView(viewState);
  toast('Panoramica salvata nel JSON locale');
}

function snapAudioCueToBeat(time,duration){
  const bpm=Number(audioAnalysisCurrentResult?.bpm||0);
  if(bpm<=0)return Math.max(0,Math.min(duration,time));
  const beatDuration=audioBeatDuration(audioAnalysisCurrentResult);
  const gridOffset=Number(audioAnalysisCurrentResult?.vdj_grid_phase_seconds??audioAnalysisCurrentResult?.grid_offset_seconds??0);
  const snapped=gridOffset+Math.round((time-gridOffset)/beatDuration)*beatDuration;
  return Math.max(0,Math.min(duration,snapped));
}

document.addEventListener('change',event=>{
  const label=event.target.closest?.('[data-section-label]');
  if(!label||!audioAnalysisCurrentResult)return;
  const index=Number(label.dataset.sectionLabel||0);
  const section=audioLayerSections(audioAnalysisCurrentResult,audioSelectedStemLayer,Number(audioAnalysisCurrentResult.duration_seconds||0))[index];
  if(!section)return;
  const value=String(label.value||'').trim();
  if(value===section.label)return;
  section.label=value;
  saveAudioOverviewLayout().catch(error=>toast(error.message));
});

function updateAudioOverviewDrag(clientX){
  if(!audioOverviewDrag||!audioAnalysisCurrentResult)return;
  const {canvas,duration}=audioOverviewDrag;
  if(audioOverviewDrag.type==='grid'){
    const deltaPixels=clientX-audioOverviewDrag.startX;
    audioAnalysisCurrentResult.grid_offset_seconds=audioOverviewDrag.initialOffset+deltaPixels/Math.max(1,canvas.getBoundingClientRect().width)*duration;
    canvas.querySelector('.audio-overview-beat-grid').style.transform=`translateX(${deltaPixels}px)`;
    audioOverviewDrag.handle.querySelector('small').textContent=audioMusicalPosition(0,audioAnalysisCurrentResult);
    return;
  }
  const bounds=canvas.getBoundingClientRect();
  const rawTime=Math.max(0,Math.min(duration,(clientX-bounds.left)/Math.max(1,bounds.width)*duration));
  const time=audioOverviewDrag.type==='section'?snapAudioCueToBeat(rawTime,duration):rawTime;
  if(audioOverviewDrag.type==='section'){
    const index=audioOverviewDrag.index;
    const sections=audioLayerSections(audioAnalysisCurrentResult,audioSelectedStemLayer,duration);
    const current=sections[index];
    const next=sections[index+1];
    if(!current||!next)return;
    const boundary=Math.max(Number(current.start)+.1,Math.min(Number(next.end)-.1,time));
    current.end=boundary;
    next.start=boundary;
    const currentElement=canvas.querySelector(`[data-section-index="${index}"]`);
    const nextElement=canvas.querySelector(`[data-section-index="${index+1}"]`);
    const cueElement=canvas.querySelector(`.audio-layer-cue[data-section-boundary="${index}"]`);
    if(currentElement)currentElement.style.width=`${(boundary-Number(current.start))/duration*100}%`;
    if(nextElement){nextElement.style.left=`${boundary/duration*100}%`;nextElement.style.width=`${(Number(next.end)-boundary)/duration*100}%`}
    if(cueElement)cueElement.style.left=`${boundary/duration*100}%`;
  }else{
    const items=audioAnalysisCurrentResult[audioOverviewDrag.list]||[];
    const cue=items[audioOverviewDrag.index];
    if(!cue)return;
    const cueDuration=audioOverviewDrag.cueDuration;
    const start=Math.max(0,Math.min(duration-cueDuration,time));
    cue.start=start;cue.time=start;cue.end=start+cueDuration;
    const marker=canvas.querySelector(`[data-overview-cue="${audioOverviewDrag.list}"][data-cue-index="${audioOverviewDrag.index}"]`);
    if(marker)marker.style.left=`${start/duration*100}%`;
  }
}

document.addEventListener('pointerdown',event=>{
  const boundary=event.target.closest('[data-section-boundary]');
  const cue=event.target.closest('[data-overview-cue]');
  const grid=event.target.closest('[data-grid-drag]');
  if(!boundary&&!cue&&!grid||!audioAnalysisCurrentResult)return;
  const canvas=grid?grid.closest('.audio-overview')?.querySelector('.audio-overview-canvas'):(boundary||cue).closest('.audio-overview-canvas');
  const duration=Math.max(.1,Number(audioAnalysisCurrentResult.duration_seconds||0));
  if(!canvas||duration<=0)return;
  event.preventDefault();
  if(grid)audioOverviewDrag={type:'grid',canvas,duration,startX:event.clientX,initialOffset:Number(audioAnalysisCurrentResult.grid_offset_seconds||0),handle:grid};
  else if(boundary)audioOverviewDrag={type:'section',index:Number(boundary.dataset.sectionBoundary||0),canvas,duration};
  else{
    const list=cue.dataset.overviewCue;
    const index=Number(cue.dataset.cueIndex||0);
    const item=audioAnalysisCurrentResult[list]?.[index];
    if(!item)return;
    audioOverviewDrag={type:'cue',list,index,canvas,duration,cueDuration:Math.max(.1,Number(item.end)-Number(item.start))};
  }
  (boundary||cue||grid).setPointerCapture?.(event.pointerId);
  document.body.classList.add('audio-overview-dragging');
});

document.addEventListener('pointermove',event=>{
  if(audioOverviewDrag)updateAudioOverviewDrag(event.clientX);
});

document.addEventListener('pointerup',()=>{
  if(!audioOverviewDrag)return;
  audioOverviewDrag=null;
  document.body.classList.remove('audio-overview-dragging');
  saveAudioOverviewLayout().catch(error=>toast(error.message));
});

document.addEventListener('dblclick',event=>{
  const grid=event.target.closest('[data-grid-drag]');
  if(grid&&audioAnalysisCurrentResult){event.preventDefault();audioAnalysisCurrentResult.grid_offset_seconds=0;saveAudioOverviewLayout().catch(error=>toast(error.message));return}
  const canvas=event.target.closest('.audio-overview-canvas');
  if(!canvas||event.target.closest('[data-section-boundary],[data-section-label],[data-stem-toggle],[data-automix-poi]')||!audioAnalysisCurrentResult)return;
  if(!audioSelectedStemLayer){toast('Seleziona prima un layer stem');return}
  const duration=Math.max(.1,Number(audioAnalysisCurrentResult.duration_seconds||0));
  const bounds=canvas.getBoundingClientRect();
  const rawTime=Math.max(.1,Math.min(duration-.1,(event.clientX-bounds.left)/Math.max(1,bounds.width)*duration));
  const time=Math.max(.1,Math.min(duration-.1,snapAudioCueToBeat(rawTime,duration)));
  const sections=audioLayerSections(audioAnalysisCurrentResult,audioSelectedStemLayer,duration);
  const index=sections.findIndex(section=>time>Number(section.start)+.1&&time<Number(section.end)-.1);
  if(index<0)return;
  const current=sections[index];
  const labels=audioStemCueLabels[audioSelectedStemLayer]||['SEZIONE'];
  const currentLabel=labels.includes(current.label)?current.label:labels[0];
  const nextLabel=labels[(labels.indexOf(currentLabel)+1)%labels.length];
  const oldEnd=Number(current.end);
  current.end=time;
  sections.splice(index+1,0,{start:time,end:oldEnd,label:nextLabel,manual:true});
  saveAudioOverviewLayout().catch(error=>toast(error.message));
});

document.addEventListener('contextmenu',event=>{
  const boundary=event.target.closest('[data-section-boundary]');
  if(!boundary||!audioAnalysisCurrentResult||!audioSelectedStemLayer)return;
  event.preventDefault();
  const sections=audioLayerSections(audioAnalysisCurrentResult,audioSelectedStemLayer,Number(audioAnalysisCurrentResult.duration_seconds||0));
  const index=Number(boundary.dataset.sectionBoundary||0);
  if(!sections[index]||!sections[index+1])return;
  sections[index].end=sections[index+1].end;
  sections.splice(index+1,1);
  saveAudioOverviewLayout().catch(error=>toast(error.message));
});

document.addEventListener('click',async event=>{
  const stemButton=event.target.closest('[data-stem-toggle]');
  if(!stemButton)return;
  const panel=stemButton.closest('#audio-analysis-result,#audio-analysis-result-2');
  activateAudioAnalysisPanel(panel);
  const viewState=captureAudioOverviewView();
  const playback=audioAnalysisPreview?{currentTime:audioAnalysisPreview.currentTime,start:audioAnalysisPreviewStart,end:audioAnalysisPreviewEnd}:null;
  stopAudioAnalysisPreview();
  const key=stemButton.dataset.stemToggle;
  const active=Boolean(audioStemVisibility.get(key));
  const selected=audioSelectedStemLayer===key;
  if(!active){audioStemVisibility.set(key,true)}
  else if(!selected){audioSelectedStemLayer=key;audioStemVisibility.set(key,true)}
  else{audioStemVisibility.set(key,false);audioSelectedStemLayer=''}
  audioStemStates.set(Number(audioAnalysisCurrentResult?.track_id||0),{selected:audioSelectedStemLayer,visibility:new Map(audioStemVisibility)});
  if(audioSelectedStemLayer)audioLayerSections(audioAnalysisCurrentResult,audioSelectedStemLayer,Number(audioAnalysisCurrentResult.duration_seconds||0));
  if(panel?.id==='audio-analysis-result-2')renderAudioAnalysisSecondary(audioAnalysisCurrentResult,audioAnalysisTracks.get(Number(audioAnalysisCurrentResult.track_id||0)));
  else renderAudioAnalysis(audioAnalysisCurrentResult,audioAnalysisTracks.get(Number(audioAnalysisCurrentResult.track_id||0)));
  restoreAudioOverviewView({...viewState,playing:false});
  try{await saveAudioOverviewLayout();if(playback)resumeAudioAnalysisPreview(playback)}catch(error){toast(error.message)}
});

function setAudioOverviewZoom(overview,zoom,anchorClientX=null){
  const viewport=overview?.querySelector('.audio-overview-viewport');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  if(!canvas||!viewport)return;
  const oldWidth=Math.max(1,canvas.getBoundingClientRect().width);
  const viewportBounds=viewport.getBoundingClientRect();
  const localX=anchorClientX===null?viewport.clientWidth/2:Math.max(0,Math.min(viewport.clientWidth,anchorClientX-viewportBounds.left));
  const anchorRatio=(viewport.scrollLeft+localX)/oldWidth;
  zoom=Math.max(.5,Math.min(8,Number(zoom)||1));
  canvas.dataset.audioZoomValue=String(zoom);
  const syncedPixelsPerBeat=Number(canvas.dataset.audioPixelsPerBeat||0);
  const syncedBeatCount=Number(canvas.dataset.audioBeatCount||0);
  if(syncedPixelsPerBeat>0&&syncedBeatCount>0)canvas.style.width=`${syncedPixelsPerBeat*syncedBeatCount*zoom}px`;
  else{
    const tempoScale=Math.max(.25,Math.min(4,Number(canvas.dataset.audioTempoScale||1)));
    canvas.style.width=`${zoom*tempoScale*100}%`;
  }
  overview.querySelector('[data-audio-zoom-label]').textContent=`${Math.round(zoom*100)}%`;
  if(zoom===1)viewport.scrollLeft=0;
  else viewport.scrollLeft=Math.max(0,anchorRatio*canvas.getBoundingClientRect().width-localX);
  applyAudioTrackShift(canvas);
  if($('#view-audio-analysis')?.classList.contains('audio-comparison-mode')){
    const otherOverview=$$('.audio-overview').find(item=>item!==overview&&item.closest('#audio-analysis-result,#audio-analysis-result-2'));
    const otherViewport=otherOverview?.querySelector('.audio-overview-viewport');
    const otherCanvas=otherOverview?.querySelector('.audio-overview-canvas');
    if(otherViewport&&otherCanvas){
      otherCanvas.dataset.audioZoomValue=String(zoom);
      const otherPixelsPerBeat=Number(otherCanvas.dataset.audioPixelsPerBeat||0);
      const otherBeatCount=Number(otherCanvas.dataset.audioBeatCount||0);
      if(otherPixelsPerBeat>0&&otherBeatCount>0)otherCanvas.style.width=`${otherPixelsPerBeat*otherBeatCount*zoom}px`;
      else{
        const otherTempoScale=Math.max(.25,Math.min(4,Number(otherCanvas.dataset.audioTempoScale||1)));
        otherCanvas.style.width=`${zoom*otherTempoScale*100}%`;
      }
      applyAudioTrackShift(otherCanvas);
      equalizeAudioCanvasScrollWidths();
      otherOverview.querySelector('[data-audio-zoom-label]').textContent=`${Math.round(zoom*100)}%`;
      if($('#view-audio-analysis')?.classList.contains('audio-tempo-synced'))otherViewport.scrollLeft=viewport.scrollLeft;
      else{
        const sourceRange=Math.max(1,viewport.scrollWidth-viewport.clientWidth);
        const scrollRatio=zoom===1?0:viewport.scrollLeft/sourceRange;
        otherViewport.scrollLeft=scrollRatio*Math.max(0,otherViewport.scrollWidth-otherViewport.clientWidth);
      }
    }
    scheduleAudioKeyCompatibility();
  }
  scheduleAudioTransitionHighlight();
}

function updateAudioCanvasTailSpaces(){
  const canvases=[$('#audio-analysis-result .audio-overview-canvas'),$('#audio-analysis-result-2 .audio-overview-canvas')].filter(Boolean);
  if(!canvases.length)return;
  canvases.forEach(canvas=>canvas.style.marginRight='0px');
  const widths=canvases.map(canvas=>canvas.getBoundingClientRect().width);
  const synced=$('#view-audio-analysis')?.classList.contains('audio-tempo-synced')&&canvases.length===2;
  const maximum=synced?Math.max(...widths):0;
  canvases.forEach((canvas,index)=>{
    const viewport=canvas.closest('.audio-overview-viewport');
    const tailSpace=(viewport?.clientWidth||0)/2;
    canvas.style.marginRight=`${tailSpace+(synced?Math.max(0,maximum-widths[index]):0)}px`;
  });
}

function equalizeAudioCanvasScrollWidths(){
  updateAudioCanvasTailSpaces();
}

function applyAudioTrackShift(canvas){
  if(!canvas)return;
  const panel=canvas.closest('#audio-analysis-result,#audio-analysis-result-2');
  if(panel?.id!=='audio-analysis-result-2'){canvas.style.translate='0 0';return}
  const trackId=Number(panel.dataset.trackId||0);
  const result=audioAnalysisResults.get(trackId);
  const manualBeats=Math.round(Number(canvas.dataset.trackShiftBeats||0)/4)*4;
  canvas.dataset.trackShiftBeats=String(manualBeats);
  const beats=manualBeats+Number(canvas.dataset.trackSyncBaseBeats||0);
  const bpm=Math.max(1,Number(result?.bpm||120));
  const duration=Math.max(.01,Number(result?.duration_seconds||1));
  const width=canvas.getBoundingClientRect().width;
  const pixels=beats*(60/bpm)/duration*width;
  canvas.style.translate=`${pixels}px 0`;
  const label=panel.querySelector('[data-track-shift-label]');
  if(label){const bars=beats/4;label.textContent=`${bars>0?'+':''}${bars} ${Math.abs(bars)===1?'battuta':'battute'}`}
  captureAudioPanelView(panel);
  scheduleAudioKeyCompatibility();
  scheduleAudioTransitionHighlight();
}

document.addEventListener('pointerdown',event=>{
  const button=event.target.closest('[data-track-shift]');
  if(!button)return;
  const panel=button.closest('#audio-analysis-result-2');
  const canvas=panel?.querySelector('.audio-overview-canvas');
  const result=audioAnalysisResults.get(Number(panel?.dataset.trackId||0));
  if(!canvas||!result)return;
  event.preventDefault();
  button.setPointerCapture?.(event.pointerId);
  const bpm=Math.max(1,Number(result.bpm||120));
  const duration=Math.max(.01,Number(result.duration_seconds||1));
  const pixelsPerBeat=canvas.getBoundingClientRect().width*(60/bpm)/duration;
  audioTrackShiftDrag={button,canvas,startX:event.clientX,startBeats:Number(canvas.dataset.trackShiftBeats||0),pixelsPerBeat:Math.max(.1,pixelsPerBeat)};
  document.body.classList.add('audio-track-shifting');
});

document.addEventListener('pointermove',event=>{
  if(!audioTrackShiftDrag)return;
  const beats=Math.round((audioTrackShiftDrag.startBeats+(event.clientX-audioTrackShiftDrag.startX)/audioTrackShiftDrag.pixelsPerBeat)/4)*4;
  audioTrackShiftDrag.canvas.dataset.trackShiftBeats=String(beats);
  audioAutomixPoints=null;
  applyAudioTrackShift(audioTrackShiftDrag.canvas);
});

document.addEventListener('pointerup',()=>{
  if(!audioTrackShiftDrag)return;
  audioTrackShiftDrag=null;
  document.body.classList.remove('audio-track-shifting');
});

document.addEventListener('dblclick',event=>{
  const button=event.target.closest('[data-track-shift]');
  const canvas=button?.closest('#audio-analysis-result-2')?.querySelector('.audio-overview-canvas');
  if(!canvas)return;
  canvas.dataset.trackShiftBeats='0';
  audioAutomixPoints=null;
  applyAudioTrackShift(canvas);
});

document.addEventListener('click',event=>{
  const button=event.target.closest('[data-sync-audio-analysis]');
  if(!button)return;
  const view=$('#view-audio-analysis');
  if(view?.classList.contains('audio-tempo-synced')){stopAudioComparisonPreview();return}
  const panels=[$('#audio-analysis-result'),$('#audio-analysis-result-2')];
  const items=panels.map(panel=>{
    const trackId=Number(panel?.dataset.trackId||0);
    const result=audioAnalysisResults.get(trackId);
    const canvas=panel?.querySelector('.audio-overview-canvas');
    return trackId&&result&&canvas?{trackId,result,canvas}:null;
  }).filter(Boolean);
  if(items.length!==2){toast('Servono due analisi complete per il SYNC');return}
  const primaryBpm=Math.max(1,Number(items[0].result.bpm||120));
  const secondaryBpm=Math.max(1,Number(items[1].result.bpm||120));
  const primaryDuration=Math.max(.01,Number(items[0].result.duration_seconds||1));
  const secondaryDuration=Math.max(.01,Number(items[1].result.duration_seconds||1));
  const primaryBeatCount=primaryDuration*primaryBpm/60;
  const secondaryBeatCount=secondaryDuration*secondaryBpm/60;
  const viewportWidth=Math.min(...items.map(item=>item.canvas.closest('.audio-overview-viewport')?.clientWidth||1));
  const pixelsPerBeat=viewportWidth/Math.max(1,Math.min(primaryBeatCount,secondaryBeatCount));
  items[0].canvas.dataset.audioPixelsPerBeat=String(pixelsPerBeat);
  items[1].canvas.dataset.audioPixelsPerBeat=String(pixelsPerBeat);
  items[0].canvas.dataset.audioBeatCount=String(primaryBeatCount);
  items[1].canvas.dataset.audioBeatCount=String(secondaryBeatCount);
  items[0].canvas.dataset.audioTempoScale='1';
  items[1].canvas.dataset.audioTempoScale='1';
  view?.classList.add('audio-tempo-synced');
  const zoom=Number(items[0].canvas.dataset.audioZoomValue||1);
  setAudioOverviewZoom(items[0].canvas.closest('.audio-overview'),zoom);
  equalizeAudioCanvasScrollWidths();
  const primaryWidth=items[0].canvas.getBoundingClientRect().width;
  const secondaryWidth=items[1].canvas.getBoundingClientRect().width;
  const secondaryBeatPixels=secondaryWidth*(60/secondaryBpm)/secondaryDuration;
  const primaryGridPixels=audioPhraseGridOffset(items[0].result)/primaryDuration*primaryWidth;
  const secondaryGridPixels=audioPhraseGridOffset(items[1].result)/secondaryDuration*secondaryWidth;
  items[1].canvas.dataset.trackSyncBaseBeats=String((primaryGridPixels-secondaryGridPixels)/Math.max(.1,secondaryBeatPixels));
  audioAutomixPoints=null;
  applyAudioTrackShift(items[1].canvas);
  audioTempoSyncPair={
    primaryTrackId:items[0].trackId,
    secondaryTrackId:items[1].trackId,
    primaryBpm,
    secondaryBpm,
  };
  panels.forEach(panel=>captureAudioPanelView(panel));
  button.textContent=`SYNC ${secondaryBpm.toFixed(2)} → ${primaryBpm.toFixed(2)} BPM`;
  button.classList.add('is-playing');
  refreshAudioTempoSyncButton();
});

function stopAudioTransitionPreview(){
  clearTimeout(audioTransitionTimer);
  cancelAnimationFrame(audioTransitionFrame);
  audioTransitionFrame=null;
  audioTransitionAudios.forEach(audio=>audio.pause());
  audioTransitionAudios=[];
  $('.audio-transition-playhead-layer')?.remove();
  $$('[data-preview-transition]').forEach(button=>{button.textContent='▶ Transizione';button.classList.remove('is-playing')});
}

function startAudioTransitionPlayhead(overlapStart,overlapEnd,top,bottom,durationSeconds){
  $('.audio-transition-playhead-layer')?.remove();
  const view=$('#view-audio-analysis');
  if(!view)return;
  const viewRect=view.getBoundingClientRect();
  const layer=document.createElement('div');
  layer.className='audio-transition-playhead-layer';
  layer.innerHTML='<i><b>TRANSIZIONE</b></i>';
  const line=layer.firstElementChild;
  line.style.top=`${top-viewRect.top}px`;
  line.style.height=`${Math.max(1,bottom-top)}px`;
  view.appendChild(layer);
  const startedAt=performance.now();
  const draw=now=>{
    const progress=Math.max(0,Math.min(1,(now-startedAt)/Math.max(1,durationSeconds*1000)));
    line.style.left=`${overlapStart-viewRect.left+(overlapEnd-overlapStart)*progress}px`;
    if(progress<1&&audioTransitionAudios.length)audioTransitionFrame=requestAnimationFrame(draw);
  };
  audioTransitionFrame=requestAnimationFrame(draw);
}

function audioTransitionSnap(time,result){
  const duration=Math.max(.01,Number(result?.duration_seconds||1));
  const phrase=audioBeatDuration(result)||.5;
  const offset=Number(result?.vdj_grid_phase_seconds??result?.grid_offset_seconds??0);
  return Math.max(0,Math.min(duration,offset+Math.round((time-offset)/phrase)*phrase));
}

function audioTransitionSelection(){
  const panels=[$('#audio-analysis-result'),$('#audio-analysis-result-2')];
  const items=panels.map(panel=>{
    const trackId=Number(panel?.dataset.trackId||0);
    const result=audioAnalysisResults.get(trackId);
    const canvas=panel?.querySelector('.audio-overview-canvas');
    const viewport=panel?.querySelector('.audio-overview-viewport');
    return trackId&&result&&canvas&&viewport?{trackId,result,canvas,viewport}:null;
  }).filter(Boolean);
  if(items.length!==2)return null;
  const canvasRects=items.map(item=>item.canvas.getBoundingClientRect());
  const viewportRects=items.map(item=>item.viewport.getBoundingClientRect());
  const overlapStart=Math.max(canvasRects[0].left,canvasRects[1].left,viewportRects[0].left,viewportRects[1].left);
  const overlapEnd=Math.min(canvasRects[0].right,canvasRects[1].right,viewportRects[0].right,viewportRects[1].right);
  if(overlapEnd-overlapStart<8)return null;
  items.forEach((item,index)=>{
    const duration=Math.max(.01,Number(item.result.duration_seconds||1));
    item.start=Math.max(0,Math.min(duration,(overlapStart-canvasRects[index].left)/canvasRects[index].width*duration));
    item.end=Math.max(item.start,Math.min(duration,(overlapEnd-canvasRects[index].left)/canvasRects[index].width*duration));
  });
  return {items,canvasRects,overlapStart,overlapEnd};
}

function updateAudioTransitionHighlight(){
  $$('.audio-transition-highlight').forEach(item=>item.remove());
  $$('.audio-automix-poi').forEach(item=>item.remove());
  const selection=audioTransitionSelection();
  if(!selection)return;
  const outgoingTrackId=selection.items[0].trackId;
  const incomingTrackId=selection.items[1].trackId;
  if(!audioAutomixPoints||audioAutomixPoints.outgoingTrackId!==outgoingTrackId||audioAutomixPoints.incomingTrackId!==incomingTrackId){
    audioAutomixPoints={
      outgoingTrackId,
      incomingTrackId,
      track1In:audioTransitionSnap(selection.items[0].start,selection.items[0].result),
      track1Out:audioTransitionSnap(selection.items[0].end,selection.items[0].result),
      track2In:audioTransitionSnap(selection.items[1].start,selection.items[1].result),
      track2Out:audioTransitionSnap(selection.items[1].end,selection.items[1].result),
      manual:false,
    };
  }else if(!audioAutomixPoints.manual){
    audioAutomixPoints.track1In=audioTransitionSnap(selection.items[0].start,selection.items[0].result);
    audioAutomixPoints.track1Out=audioTransitionSnap(selection.items[0].end,selection.items[0].result);
    audioAutomixPoints.track2In=audioTransitionSnap(selection.items[1].start,selection.items[1].result);
    audioAutomixPoints.track2Out=audioTransitionSnap(selection.items[1].end,selection.items[1].result);
  }
  selection.items.forEach((item,index)=>{
    const rect=selection.canvasRects[index];
    const marker=document.createElement('div');
    marker.className='audio-transition-highlight';
    marker.style.left=`${(selection.overlapStart-rect.left)/rect.width*100}%`;
    marker.style.width=`${(selection.overlapEnd-selection.overlapStart)/rect.width*100}%`;
    marker.innerHTML='<span>TRANSIZIONE</span>';
    item.canvas.appendChild(marker);
    const pointNumber=index+1;
    [['in',audioAutomixPoints[`track${pointNumber}In`]],['out',audioAutomixPoints[`track${pointNumber}Out`]]].forEach(([role,time])=>{
      const poi=document.createElement('button');
      const boundaryNumber=role==='in'?1:2;
      const trackRole=index===0?'OUT':'IN';
      poi.type='button';
      poi.className=`audio-automix-poi ${role}`;
      poi.dataset.automixPoi=`track${pointNumber}${role==='in'?'In':'Out'}`;
      poi.style.left=`${time/Math.max(.01,Number(item.result.duration_seconds||1))*100}%`;
      poi.innerHTML=`<b>${boundaryNumber} ${trackRole}</b><small>${audioAbsoluteBeatPosition(time,item.result)}</small>`;
      item.canvas.appendChild(poi);
    });
  });
}

function scheduleAudioTransitionHighlight(){
  requestAnimationFrame(updateAudioTransitionHighlight);
}

document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-send-transition-automix]');
  if(!button)return;
  const selection=audioTransitionSelection();
  if(!selection){toast('Nessuna zona di transizione sovrapposta');return}
  button.disabled=true;
  try{
    const outgoing=selection.items[0];
    const incoming=selection.items[1];
    const points=audioAutomixPoints&&audioAutomixPoints.outgoingTrackId===outgoing.trackId&&audioAutomixPoints.incomingTrackId===incoming.trackId
      ?audioAutomixPoints
      :{track1In:audioTransitionSnap(outgoing.start,outgoing.result),track1Out:audioTransitionSnap(outgoing.end,outgoing.result),track2In:audioTransitionSnap(incoming.start,incoming.result),track2Out:audioTransitionSnap(incoming.end,incoming.result)};
    const response=await post('audio-analysis-automix-transition',{
      outgoing_id:outgoing.trackId,
      incoming_id:incoming.trackId,
      outgoing_in:points.track1In,
      outgoing_out:points.track1Out,
      incoming_in:points.track2In,
      incoming_out:points.track2Out,
      transition_beats:Math.max(1,Math.round((outgoing.end-outgoing.start)*Math.max(1,Number(outgoing.result.bpm||120))/60)),
    });
    toast('CustomMix VDJ: OUT '+Number(response.outgoing_in_seconds||0).toFixed(3)+'s · IN '+Number(response.incoming_in_seconds||0).toFixed(3)+'s · durata '+Number(response.transition_seconds||0).toFixed(3)+'s');
  }catch(error){toast(error.message)}
  finally{button.disabled=false}
});

document.addEventListener('pointerdown',event=>{
  const poi=event.target.closest('[data-automix-poi]');
  if(!poi||!audioAutomixPoints)return;
  const panel=poi.closest('#audio-analysis-result,#audio-analysis-result-2');
  const trackId=Number(panel?.dataset.trackId||0);
  const result=audioAnalysisResults.get(trackId);
  const canvas=poi.closest('.audio-overview-canvas');
  if(!result||!canvas)return;
  event.preventDefault();
  poi.setPointerCapture?.(event.pointerId);
  audioAutomixPoiDrag={poi,canvas,result,role:poi.dataset.automixPoi};
  document.body.classList.add('audio-automix-poi-dragging');
});

document.addEventListener('pointermove',event=>{
  if(!audioAutomixPoiDrag||!audioAutomixPoints)return;
  const {poi,canvas,result,role}=audioAutomixPoiDrag;
  const duration=Math.max(.01,Number(result.duration_seconds||1));
  const bounds=canvas.getBoundingClientRect();
  const rawTime=Math.max(0,Math.min(duration,(event.clientX-bounds.left)/Math.max(1,bounds.width)*duration));
  const time=audioTransitionSnap(rawTime,result);
  audioAutomixPoints[role]=time;
  audioAutomixPoints.manual=true;
  poi.style.left=`${time/duration*100}%`;
  poi.querySelector('small').textContent=audioMusicalPosition(time,result);
});

document.addEventListener('pointerup',()=>{
  if(!audioAutomixPoiDrag)return;
  audioAutomixPoiDrag=null;
  document.body.classList.remove('audio-automix-poi-dragging');
});

document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-preview-transition]');
  if(!button)return;
  if(audioTransitionAudios.length){stopAudioTransitionPreview();return}
  stopAudioAnalysisPreview();
  const panels=[$('#audio-analysis-result'),$('#audio-analysis-result-2')];
  const items=panels.map(panel=>{
    const trackId=Number(panel?.dataset.trackId||0);
    const result=audioAnalysisResults.get(trackId);
    const canvas=panel?.querySelector('.audio-overview-canvas');
    const viewport=panel?.querySelector('.audio-overview-viewport');
    const url=audioAnalysisStreamUrlForTrack(trackId);
    return trackId&&result&&canvas&&viewport&&url?{trackId,result,canvas,viewport,audio:new Audio(url)}:null;
  }).filter(Boolean);
  if(items.length!==2){toast('Servono due analisi complete per il preascolto');return}
  const selection=audioTransitionSelection();
  if(!selection){toast('Nessuna frase di transizione sovrapposta');return}
  const canvasRects=items.map(item=>item.canvas.getBoundingClientRect());
  const points=audioAutomixPoints&&audioAutomixPoints.outgoingTrackId===items[0].trackId&&audioAutomixPoints.incomingTrackId===items[1].trackId
    ?audioAutomixPoints
    :{track1In:selection.items[0].start,track1Out:selection.items[0].end,track2In:selection.items[1].start,track2Out:selection.items[1].end};
  const previewSeconds=Math.max(.5,selection.items[0].end-selection.items[0].start);
  items.forEach((item,index)=>{
    item.start=index===0?Math.max(0,points.track1In):Math.max(0,points.track2In);
    item.audio.volume=.65;
  });
  const primaryBpm=Math.max(1,Number(items[0].result.bpm||120));
  const secondaryBpm=Math.max(1,Number(items[1].result.bpm||120));
  items[1].audio.playbackRate=Math.max(.5,Math.min(2,primaryBpm/secondaryBpm));
  button.disabled=true;
  try{
    await Promise.all(items.map(item=>new Promise((resolve,reject)=>{
      item.audio.preload='auto';
      item.audio.addEventListener('loadedmetadata',()=>{item.audio.currentTime=item.start;resolve()},{once:true});
      item.audio.addEventListener('error',reject,{once:true});
    })));
    audioTransitionAudios=items.map(item=>item.audio);
    await Promise.all(audioTransitionAudios.map(audio=>audio.play()));
    button.textContent='■ Transizione';button.classList.add('is-playing');
    startAudioTransitionPlayhead(
      selection.overlapStart,
      selection.overlapEnd,
      Math.min(...canvasRects.map(rect=>rect.top)),
      Math.max(...canvasRects.map(rect=>rect.bottom)),
      previewSeconds
    );
    audioTransitionTimer=setTimeout(stopAudioTransitionPreview,previewSeconds*1000);
  }catch(error){stopAudioTransitionPreview();toast('Preascolto transizione non disponibile')}
  finally{button.disabled=false}
});

document.addEventListener('click',event=>{
  const zoomButton=event.target.closest('[data-audio-zoom]');
  if(!zoomButton)return;
  const overview=zoomButton.closest('.audio-overview');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  if(!canvas)return;
  const direction=Number(zoomButton.dataset.audioZoom||0);
  let zoom=Number(canvas.dataset.audioZoomValue||1);
  zoom=direction===0?1:Math.max(.5,Math.min(8,zoom+direction*.5));
  setAudioOverviewZoom(overview,zoom);
});

document.addEventListener('wheel',event=>{
  const viewport=event.target.closest('.audio-overview-viewport');
  if(!viewport)return;
  event.preventDefault();
  const overview=viewport.closest('.audio-overview');
  const canvas=overview?.querySelector('.audio-overview-canvas');
  if(!canvas)return;
  const current=Number(canvas.dataset.audioZoomValue||1);
  setAudioOverviewZoom(overview,current+(event.deltaY<0?.35:-.35),event.clientX);
},{passive:false});

document.addEventListener('scroll',event=>{
  const viewport=event.target.closest?.('.audio-overview-viewport');
  if(!viewport||audioLinkedScroll||!$('#view-audio-analysis')?.classList.contains('audio-tempo-synced'))return;
  const other=$$('.audio-overview-viewport').find(item=>item!==viewport&&item.closest('#audio-analysis-result,#audio-analysis-result-2'));
  if(!other)return;
  audioLinkedScroll=true;
  other.scrollLeft=viewport.scrollLeft;
  requestAnimationFrame(()=>{audioLinkedScroll=false;scheduleAudioKeyCompatibility()});
  scheduleAudioTransitionHighlight();
},true);

function dropWaveformSamples(result,cue,windowStart,windowEnd,zoom){
  if(zoom>=1)return Array.isArray(cue.waveform)?cue.waveform:[];
  const overview=Array.isArray(result.overview_waveform)?result.overview_waveform:[];
  const duration=Math.max(.01,Number(result.duration_seconds||0));
  if(!overview.length||duration<=0)return Array.isArray(cue.waveform)?cue.waveform:[];
  const first=Math.max(0,Math.floor(windowStart/duration*overview.length));
  const last=Math.min(overview.length,Math.max(first+2,Math.ceil(windowEnd/duration*overview.length)+1));
  return overview.slice(first,last);
}

document.addEventListener('input',event=>{
  const slider=event.target.closest('[data-drop-zoom]');
  if(!slider||!audioAnalysisCurrentResult)return;
  const row=slider.closest('.audio-cue.drop');
  const cue=audioAnalysisCurrentResult.drops?.[Number(slider.dataset.dropZoom||0)];
  const oldWaveform=row?.querySelector('.audio-waveform-wrap');
  if(!row||!cue||!oldWaveform)return;
  const zoom=Math.max(0,Math.min(1,Number(slider.value||0)/100));
  const duration=Math.max(.01,Number(audioAnalysisCurrentResult.duration_seconds||0));
  const dropStart=parseAudioTime(row.querySelector('[data-drop-start]')?.value);
  const dropEnd=parseAudioTime(row.querySelector('[data-drop-end]')?.value);
  if(!Number.isFinite(dropStart)||!Number.isFinite(dropEnd))return;
  const detailStart=Number(cue.waveform_start??Math.max(0,dropStart-5));
  const detailEnd=Number(cue.waveform_end??Math.min(duration,dropEnd+5));
  const windowStart=detailStart*zoom;
  const windowEnd=duration+(detailEnd-duration)*zoom;
  const samples=dropWaveformSamples(audioAnalysisCurrentResult,cue,windowStart,windowEnd,zoom);
  const template=document.createElement('template');
  template.innerHTML=audioWaveform(samples,windowStart,windowEnd,dropStart,dropEnd,audioAnalysisCurrentResult.bpm);
  const newWaveform=template.content.firstElementChild;
  if(!newWaveform)return;
  const wasPlaying=oldWaveform===audioAnalysisPreviewWaveform;
  if(oldWaveform.classList.contains('is-playing'))newWaveform.classList.add('is-playing');
  oldWaveform.replaceWith(newWaveform);
  slider.closest('.audio-drop-zoom')?.querySelector('output').replaceChildren(`${Math.round(zoom*100)}%`);
  const preview=row.querySelector('.audio-preview');
  if(preview){
    preview.dataset.audioTime=String(windowStart);
    preview.dataset.audioLength=String(Math.max(4,windowEnd-windowStart));
  }
  if(wasPlaying){
    audioAnalysisPreviewWaveform=newWaveform;
    audioAnalysisPreviewStart=windowStart;
    audioAnalysisPreviewEnd=windowEnd;
    updateAudioAnalysisPlayhead();
  }
});

function updateDraggedDrop(){
  if(!audioDropDrag)return;
  const {row,waveform,boundary}=audioDropDrag;
  const bounds=waveform.getBoundingClientRect();
  const ratio=Math.max(0,Math.min(1,(audioDropDrag.clientX-bounds.left)/Math.max(1,bounds.width)));
  const windowStart=Number(waveform.dataset.waveformStart||0);
  const windowEnd=Number(waveform.dataset.waveformEnd||windowStart+1);
  const startInput=row.querySelector('[data-drop-start]');
  const endInput=row.querySelector('[data-drop-end]');
  let start=parseAudioTime(startInput.value),end=parseAudioTime(endInput.value);
  const selectedTime=windowStart+(windowEnd-windowStart)*ratio;
  if(boundary==='start')start=Math.min(selectedTime,end-.1);
  else end=Math.max(selectedTime,start+.1);
  startInput.value=audioPreciseTime(start);
  endInput.value=audioPreciseTime(end);
  const left=Math.max(0,Math.min(100,(start-windowStart)/(windowEnd-windowStart)*100));
  const width=Math.max(0,Math.min(100-left,(end-start)/(windowEnd-windowStart)*100));
  const range=waveform.querySelector('.audio-waveform-drop-range');
  range.style.left=`${left}%`;
  range.style.width=`${width}%`;
  range.querySelector('.audio-drop-handle.start').dataset.time=audioPreciseTime(start);
  range.querySelector('.audio-drop-handle.end').dataset.time=audioPreciseTime(end);
  row.classList.add('drop-dirty');
}

document.addEventListener('pointerdown',event=>{
  const handle=event.target.closest('[data-drop-handle]');
  if(!handle)return;
  event.preventDefault();
  const waveform=handle.closest('.audio-waveform-wrap');
  const row=handle.closest('.audio-cue');
  audioDropDrag={boundary:handle.dataset.dropHandle,waveform,row,clientX:event.clientX};
  handle.setPointerCapture?.(event.pointerId);
  document.body.classList.add('audio-drop-dragging');
});

document.addEventListener('pointermove',event=>{
  if(!audioDropDrag)return;
  audioDropDrag.clientX=event.clientX;
  updateDraggedDrop();
});

document.addEventListener('pointerup',()=>{
  if(!audioDropDrag)return;
  audioDropDrag=null;
  document.body.classList.remove('audio-drop-dragging');
  toast('Tempi cue point aggiornati - premi Salva per confermare');
});
