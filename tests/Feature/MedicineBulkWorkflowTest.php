<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\MedicineImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
class MedicineBulkWorkflowTest extends TestCase
{
 protected function setUp(): void {
  parent::setUp(); config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']); DB::purge('sqlite');
  Schema::create('medicine_category',function(Blueprint $t){$t->id();$t->string('name');$t->integer('status');$t->timestamps();});
  Schema::create('medicine',function(Blueprint $t){$t->id();foreach(['name','image','description','works'] as $c)$t->string($c);foreach(['pharmacy_id','medicine_category_id','status','incoming_stock','total_stock','use_stock','price_pr_strip','number_of_medicine','prescription_required'] as $c)$t->integer($c);$t->timestamps();});
  DB::table('medicine_category')->insert(['id'=>1,'name'=>'Synthetic category','status'=>1]);
 }
 private function csv(array $rows, ?array $header=null): UploadedFile {
  $h=fopen('php://temp','r+');fputcsv($h,$header??MedicineImportService::COLUMNS);foreach($rows as $row)fputcsv($h,$row);rewind($h);$body=stream_get_contents($h);fclose($h);return UploadedFile::fake()->createWithContent('synthetic.csv',$body);
 }
 private function row(string $name): array {return [$name,'Synthetic category','120','10','5','Synthetic description','Pharmacist-reviewed label','yes','no'];}
 public function test_thousand_rows_preview_and_atomic_import():void {
  $rows=[];for($i=0;$i<1000;$i++)$rows[]=$this->row('Synthetic product '.$i);
  $service=new MedicineImportService();$preview=$service->import($this->csv($rows),7,true);
  $this->assertSame(1000,$preview['created']);$this->assertSame(0,DB::table('medicine')->count());
  $this->assertSame(['created'=>1000,'updated'=>0],$service->import($this->csv($rows),7));
  $this->assertSame(1000,DB::table('medicine')->where('pharmacy_id',7)->count());
 }
 public function test_invalid_row_prevents_entire_import():void {
  $bad=$this->row('Bad');$bad[4]='-1';try{(new MedicineImportService())->import($this->csv([$this->row('Good'),$bad]),7);$this->fail('Invalid stock accepted');}catch(ValidationException $e){$this->assertSame(0,DB::table('medicine')->count());}
 }
 public function test_restock_preserves_omitted_flags_and_other_pharmacy():void {
  $service=new MedicineImportService();$service->import($this->csv([$this->row('Same')]),7);$service->import($this->csv([$this->row('Same')]),8);
  $row=array_slice($this->row('Same'),0,7);$service->import($this->csv([$row],array_slice(MedicineImportService::COLUMNS,0,7)),7);
  $a=DB::table('medicine')->where('pharmacy_id',7)->first();$b=DB::table('medicine')->where('pharmacy_id',8)->first();
  $this->assertSame(10,$a->total_stock);$this->assertSame(1,$a->prescription_required);$this->assertSame(0,$a->status);$this->assertSame(5,$b->total_stock);
 }
 public function test_aliased_duplicate_headers_and_extra_cells_are_rejected():void {
  foreach([[$this->row('A'),array_merge(MedicineImportService::COLUMNS,['medicine_name'])],[array_merge($this->row('B'),['unquoted excess']),MedicineImportService::COLUMNS]] as [$row,$header]){
   try{(new MedicineImportService())->import($this->csv([$row],$header),7);$this->fail('Malformed CSV accepted');}catch(ValidationException $e){$this->assertSame(0,DB::table('medicine')->count());}
  }
 }
}
