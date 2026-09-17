<?php
use App\Libraries\ExternalEncryptionJobService;
use App\Libraries\ExternalJobMetadata;
use App\Libraries\ExternalJobValidationException;
use App\Libraries\OperatorAuthService;
use App\Models\UserModel;
use App\Models\ExternalEncryptionJobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class ExternalJobMetadataApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    protected $namespace = 'App';

    private function account(string $name, string $role = 'distributor'): array
    {
        $email = $name . '@metadata.test';
        $id = (new UserModel())->insert(['email'=>$email,'name'=>$name,'password_hash'=>password_hash('test-password', PASSWORD_DEFAULT),'role'=>$role,'status'=>'active'], true);
        return [$id, (new OperatorAuthService())->login($email, 'test-password', null, null)['token']];
    }
    private function input(): array
    {
        return ['title'=>'Tool film','asset_type'=>'featured','creation_key'=>'metadata-test-creation-key','synopsis'=>'Synopsis from tool','language'=>'Indonesian','subtitles'=>'English','age_rating'=>'13+','production_year'=>'2026','release_date'=>'2026-01-01','distributor_company'=>'Test Distributor'];
    }
    public function testCreateReplayEditAndForeignAccess(): void
    {
        [$owner,$token] = $this->account('owner');
        $headers=['Authorization'=>'Bearer '.$token];
        $this->withHeaders($headers)->get('/api/external-encryption/options')->assertOK();
        $first=$this->withHeaders($headers)->post('/api/external-encryption/jobs',$this->input());$first->assertOK();
        $job=json_decode($first->response()->getBody(),true)['data'];
        $retry=$this->withHeaders($headers)->post('/api/external-encryption/jobs',$this->input());$retry->assertOK();
        $this->assertSame($job['id'],json_decode($retry->response()->getBody(),true)['data']['id']);
        $this->assertSame(1,(new ExternalEncryptionJobModel())->where('owner_user_id',$owner)->countAllResults());
        $edit=[...$this->input(),'edit_version'=>1,'title'=>'Updated'];
        $this->withHeaders($headers)->post('/api/external-encryption/jobs/'.$job['id'].'/metadata',$edit)->assertOK();
        $this->withHeaders($headers)->post('/api/external-encryption/jobs/'.$job['id'].'/metadata',$edit)->assertStatus(409);
        [, $other]=$this->account('other');
        $this->withHeaders(['Authorization'=>'Bearer '.$other])->get('/api/external-encryption/jobs/'.$job['id'])->assertStatus(404);
        $this->withHeaders(['Authorization'=>'Bearer '.$other])->post('/api/external-encryption/jobs/'.$job['id'].'/metadata',[...$edit,'edit_version'=>2])->assertStatus(404);
        $stored=(new ExternalEncryptionJobModel())->where('public_id',$job['id'])->first();
        $this->assertSame('Updated',json_decode($stored->metadata_json,true)['title']);
    }
    public function testClaimConflictAndDistributorFinalization(): void
    {
        [$owner] = $this->account('claims');$s=new ExternalEncryptionJobService();
        $job=$s->saveMetadata($owner,$this->input(),null);
        $edited=$s->saveMetadata($owner,[...$this->input(),'edit_version'=>1],null,$job->public_id);
        try{$s->claim($job,$owner,1);$this->fail('Stale claim accepted');}catch(RuntimeException $e){$this->assertSame(409,$e->getCode());}
        $claimed=$s->claim($edited,$owner,2)['job'];
        try{$s->saveMetadata($owner,[...$this->input(),'edit_version'=>3],null,$job->public_id);$this->fail('Claimed job edited');}catch(RuntimeException $e){$this->assertSame(409,$e->getCode());}
        $payload=['filename'=>'film.ldg','plaintext_size_bytes'=>100,'size_bytes'=>244,'plaintext_sha256'=>str_repeat('a',64),'sha256'=>str_repeat('b',64),'ldg_chunk_size'=>ExternalEncryptionJobService::CHUNK_SIZE];
        $asset=$s->finalize($claimed,$payload);
        $this->assertSame('draft',$asset->status);$this->assertSame('Synopsis from tool',$asset->synopsis);
        $this->assertSame('Test Distributor',$asset->distributor_company);
        $again=$s->finalize((new ExternalEncryptionJobModel())->find($job->id),$payload);
        $this->assertSame($asset->id,$again->id);
    }
    public function testFieldErrorsAndRoleRestrictions(): void
    {
        [, $token]=$this->account('invalid');
        $r=$this->withHeaders(['Authorization'=>'Bearer '.$token])->post('/api/external-encryption/jobs',[...$this->input(),'release_date'=>'2026-02-31','genre_ids'=>[999999]]);
        $r->assertStatus(422);$fields=json_decode($r->response()->getBody(),true)['error']['fields'];
        $this->assertArrayHasKey('release_date',$fields);$this->assertArrayHasKey('genre_ids',$fields);
        [, $op]=$this->account('operator','operator');
        $this->withHeaders(['Authorization'=>'Bearer '.$op])->get('/api/external-encryption/options')->assertStatus(403);
    }
    public function testPosterReplacementRemovalAndRollback(): void
    {
        [$owner]=$this->account('posters');$s=new ExternalEncryptionJobService();
        $file=tempnam(sys_get_temp_dir(),'poster-test-');
        file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $poster=new class($file){
            public function __construct(private string $path){}
            public function getTempName(){return $this->path;}
            public function getError(){return UPLOAD_ERR_OK;}
            public function isValid(){return true;}
            public function hasMoved(){return false;}
            public function getSize(){return filesize($this->path);}
            public function getClientName(){return 'poster.png';}
            public function getMimeType(){return 'image/png';}
        };
        $paths=[];
        try{
            $job=$s->saveMetadata($owner,$this->input(),$poster);$paths[]=$s->stagedPoster($job);
            $next=$s->saveMetadata($owner,[...$this->input(),'edit_version'=>1],$poster,$job->public_id);$paths[]=$s->stagedPoster($next);
            $this->assertFileDoesNotExist($paths[0]);$this->assertFileExists($paths[1]);
            try{$s->saveMetadata($owner,[...$this->input(),'edit_version'=>1],$poster,$job->public_id);$this->fail('Stale edit accepted');}catch(RuntimeException $e){$this->assertSame(409,$e->getCode());}
            $this->assertFileExists($paths[1]);
            $removed=$s->saveMetadata($owner,[...$this->input(),'edit_version'=>2,'remove_poster'=>'1'],null,$job->public_id);
            $this->assertNull($removed->poster_name);$this->assertFileDoesNotExist($paths[1]);
            file_put_contents($file,'not an image');
            $this->expectException(ExternalJobValidationException::class);(new ExternalJobMetadata())->poster($poster);
        }finally{@unlink($file);foreach($paths as $p)@unlink($p);}
    }
}
