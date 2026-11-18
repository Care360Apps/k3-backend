<?php
namespace App\Services;
use App\Models\Report;
class LabResultFile {
 public static function download(Report $report) {
  $name=(string)$report->private_result_name;
  abort_unless(preg_match('/\A[a-f0-9]{32}\.(pdf|jpg|jpeg|png)\z/D',$name),404);
  $folder=storage_path('app/private/medical/lab-results');$base=realpath($folder);$file=realpath($folder.DIRECTORY_SEPARATOR.$name);
  abort_unless($base&&$file&&str_starts_with($file,$base.DIRECTORY_SEPARATOR)&&is_file($file)&&!is_link($folder.DIRECTORY_SEPARATOR.$name),404);
  return response()->download($file,'care360-lab-result.'.pathinfo($name,PATHINFO_EXTENSION),['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','X-Robots-Tag'=>'noindex, nofollow']);
 }
}
