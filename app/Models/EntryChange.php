<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EntryChange extends Model {
    protected $fillable=['entry_id','user_id','before','after','reason'];
    protected function casts():array{return ['before'=>'array','after'=>'array'];}
    public function user(){return $this->belongsTo(User::class);}
}
