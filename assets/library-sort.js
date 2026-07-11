let librarySort={field:'artist',direction:1};
const libraryRenderTracks=renderTracks;
function librarySortValue(track,field){if(field==='artist')return `${track.artist||''} ${track.title||''}`.toLocaleLowerCase('it');if(field==='key')return String(track.camelot||track.musical_key||'').toLocaleLowerCase('it');if(field==='genre')return String(track.genre||'').toLocaleLowerCase('it');if(field==='format')return String(track.file_name||track.file_path||'').split('.').pop().toLocaleLowerCase('it');if(field==='tags')return Array.isArray(track.tags)?track.tags.join(' ').toLocaleLowerCase('it'):'';return Number(track[field]??0)}
function sortLibraryTracks(){const {field,direction}=librarySort;state.tracks.sort((left,right)=>{const a=librarySortValue(left,field),b=librarySortValue(right,field);return (typeof a==='string'?a.localeCompare(b,'it',{numeric:true,sensitivity:'base'}):a-b)*direction})}
function updateLibrarySortHeader(){$$('#library-sort-header [data-sort]').forEach(button=>{const active=button.dataset.sort===librarySort.field;button.classList.toggle('active',active);button.dataset.direction=active?(librarySort.direction===1?'asc':'desc'):''})}
renderTracks=function(){sortLibraryTracks();libraryRenderTracks();updateLibrarySortHeader()};
$('#library-sort-header').addEventListener('click',event=>{const button=event.target.closest('[data-sort]');if(!button)return;const field=button.dataset.sort;librarySort=field===librarySort.field?{field,direction:librarySort.direction*-1}:{field,direction:1};renderTracks()});
updateLibrarySortHeader();
