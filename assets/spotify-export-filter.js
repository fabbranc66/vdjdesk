$('#send-library-to-spotify').addEventListener('click',async event=>{
  event.preventDefault();event.stopImmediatePropagation();
  const button=event.currentTarget;
  const tracks=state.tracks.filter(track=>track.spotify_id&&track.spotify_features_updated_at&&['complete','partial'].includes(track.spotify_features_status));
  if(!tracks.length){toast('Nessun brano visibile con metriche Spotify caricate');return}
  button.disabled=true;
  if(window.setGlobalActionIcon)setGlobalActionIcon(button,krIcon.export,'Porta in Spotify to VDJ','preparazione');
  krProgress.start('Porta in Spotify to VDJ',tracks.length,'Preparazione lista filtrata');
  try{
    krProgress.update('Porta in Spotify to VDJ',tracks.length,tracks.length,'Invio alla pagina import/export');
    showView('spotify');
    const frame=$('.embedded-tool-frame');
    const send=()=>frame.contentWindow.postMessage({type:'vdjdesk-library',tracks},location.origin);
    if(frame.contentDocument?.readyState==='complete')send();else frame.addEventListener('load',send,{once:true});
    krProgress.done('Lista inviata a Spotify to VDJ',`${tracks.length.toLocaleString('it-IT')} brani`);
    toast(`${tracks.length.toLocaleString('it-IT')} brani pronti - data e Info #N saranno aggiornati riga per riga`);
  }catch(error){krProgress.fail('Invio Spotify to VDJ fallito',error.message);toast(error.message)}
  finally{button.disabled=false;if(window.setGlobalActionIcon)setGlobalActionIcon(button,krIcon.export,'Porta in Spotify to VDJ solo i brani visibili con metriche Spotify')}
},true);
