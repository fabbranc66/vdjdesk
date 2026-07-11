<?php
declare(strict_types=1);

final class TrackDeletionService
{
    private const LIBRARY_ROOT='E:\\LIBRERIA_DEFINITIVA';

    public function __construct(private PDO $pdo,private LibraryService $library){}

    public function delete(int $trackId): array
    {
        $track=$this->library->find($trackId);
        if(!$track)throw new RuntimeException('Brano non trovato.');
        $path=canonicalPath((string)$track['file_path']);
        if(!is_file($path))throw new RuntimeException('File fisico non disponibile.');
        $this->releaseVirtualDjHandle();
        $message = '';
        for ($attempt = 1; $attempt <= 3 && is_file($path); $attempt++) {
            $errorBefore=error_get_last();
            if(@unlink($path)) break;
            $error=error_get_last();
            $message=is_array($error)&&$error!==$errorBefore?(string)($error['message']??''):$message;
            usleep(350000);
        }
        if(is_file($path)){
            if(stripos($message,'Resource temporarily unavailable')!==false||stripos($message,'being used')!==false||stripos($message,'in uso')!==false){
                throw new RuntimeException('Cancellazione non riuscita: file in uso da VirtualDJ. Scaricalo dai deck/preascolto o chiudi VirtualDJ, poi riprova.');
            }
            throw new RuntimeException('Cancellazione non riuscita'.($message!==''?': '.$message:'.'));
        }
        clearstatcache(true,$path);
        if(is_file($path))throw new RuntimeException('Cancellazione non riuscita: file ancora presente.');
        $this->pdo->prepare("UPDATE tracks SET file_exists=0,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$trackId]);
        $this->pdo->prepare("UPDATE deletion_candidates SET status='deleted',decision_note='Eliminato dalla Libreria KR Desk',last_seen_at=CURRENT_TIMESTAMP WHERE source_path=?")->execute([$path]);
        return ['ok'=>true,'id'=>$trackId,'path'=>$path];
    }

    private function releaseVirtualDjHandle(): void
    {
        if(!class_exists('VirtualDjControlService')) return;
        try{(new VirtualDjControlService($this->pdo))->stopPrelisten();}catch(Throwable){}
    }

    public function move(int $trackId): array
    {
        $track=$this->library->find($trackId);
        if(!$track)throw new RuntimeException('Brano non trovato.');
        $source=canonicalPath((string)$track['file_path']);
        if(!is_file($source))throw new RuntimeException('File fisico non disponibile.');
        $destination=$this->genreFolder((string)($track['genre']??''));
        $target=$destination.'\\'.basename($source);
        if(file_exists($target))throw new RuntimeException('Nella cartella scelta esiste già un file con lo stesso nome.');
        $size=(int)filesize($source);
        if(!@rename($source,$target)){
            if(!@copy($source,$target)||(int)filesize($target)!==$size){@unlink($target);throw new RuntimeException('Spostamento non riuscito.');}
            if(!@unlink($source)){@unlink($target);throw new RuntimeException('Impossibile completare lo spostamento.');}
        }
        if(!@touch($target))throw new RuntimeException('File spostato, ma data di modifica non aggiornata.');
        $this->pdo->prepare("UPDATE tracks SET file_path=?,file_name=?,folder=?,file_size=?,file_exists=1,source='manual',updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$target,basename($target),dirname($target),(int)filesize($target),$trackId]);
        return ['ok'=>true,'track'=>$this->library->find($trackId),'old_path'=>$source,'path'=>$target];
    }

    private function genreFolder(string $genre): string
    {
        $normalized=mb_strtolower(trim($genre));
        $normalized=preg_replace('/[^\pL\pN]+/u',' ',$normalized)??$normalized;
        $map=[
            'salsa'=>'Caraibica\\Salsa','timba'=>'Caraibica\\Timba','bachata'=>'Caraibica\\Bachata',
            'cubaton'=>'Caraibica\\Cubaton','dembow'=>'Caraibica\\Dembow','reggaeton'=>'Caraibica\\Reggaeton',
            'commerciale'=>'Musica\\Commerciale_Pop','pop'=>'Musica\\Commerciale_Pop',
            'dance'=>'Musica\\Dance_EDM_House','edm'=>'Musica\\Dance_EDM_House','house'=>'Musica\\Dance_EDM_House',
            'italiano'=>'Musica\\Italiano','hip hop'=>'Urban\\Hip_Hop_USA','rap'=>'Urban\\Rap_IT','urban'=>'Urban'
        ];
        $relative=$map[$normalized]??'';
        if($relative==='')throw new RuntimeException('Nessuna cartella definitiva configurata per il genere: '.($genre!==''?$genre:'non indicato'));
        $destination=self::LIBRARY_ROOT.'\\'.$relative;
        if(!is_dir($destination))throw new RuntimeException('Cartella definitiva non disponibile: '.$destination);
        return $destination;
    }
}
