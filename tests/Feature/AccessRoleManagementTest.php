<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{AccessRole,User,Race};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AccessRoleManagementTest extends TestCase
{
    use RefreshDatabase;
    public function test_only_admins_can_manage_roles_and_assigned_roles_cannot_be_deleted(): void {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $official=User::factory()->create(['role'=>UserRole::Official]);
        $this->actingAs($official)->get('/admin/roles')->assertForbidden();
        $this->post('/admin/roles',['name'=>'Forbidden','permissions'=>[]])->assertForbidden();
        $this->actingAs($admin)->post('/admin/roles',['name'=>'Checkpoint official','permissions'=>['timings.record']])->assertSessionHasNoErrors();
        $role=AccessRole::where('name','Checkpoint official')->firstOrFail();
        $official->update(['access_role_id'=>$role->id]);
        $this->delete('/admin/roles/'.$role->id)->assertSessionHasErrors('role');
        $this->put('/admin/roles/'.$role->id,['name'=>'Checkpoint team','permissions'=>[]])->assertSessionHasNoErrors();
        $this->assertSame([], $role->fresh()->permissions);
        $official->update(['access_role_id'=>null]);
        $this->delete('/admin/roles/'.$role->id)->assertSessionHasNoErrors();
        $this->assertModelMissing($role);
        $default=AccessRole::where('is_default',true)->firstOrFail();
        $this->delete('/admin/roles/'.$default->id)->assertSessionHasErrors('role');
    }
    public function test_permissions_are_enforced_on_requests_and_still_require_race_assignment(): void {
        $role=AccessRole::create(['name'=>'Station only','permissions'=>['timings.record']]);
        $official=User::factory()->create(['role'=>UserRole::Official,'access_role_id'=>$role->id]);
        $race=Race::create(['name'=>'Assigned','slug'=>'assigned','event_date'=>'2026-09-23','created_by'=>$official->id]); $other=Race::create(['name'=>'Other','slug'=>'other','event_date'=>'2026-09-23','created_by'=>$official->id]);
        $official->races()->attach($race);
        $this->actingAs($official)->get('/races/'.$race->id.'/station')->assertOk();
        $this->get('/races/'.$other->id.'/station')->assertForbidden();
        $this->get('/races/'.$race->id.'/results')->assertOk();
        $this->get('/races/'.$race->id)->assertOk();
        foreach (['/races/create','/races/'.$race->id.'/participants','/races/'.$race->id.'/control','/races/'.$race->id.'/results.csv','/races/'.$race->id.'/results.xlsx','/users','/admin/health'] as $url) $this->get($url)->assertForbidden();
        foreach (['/races','/races/'.$race->id.'/start','/races/'.$race->id.'/finish','/races/'.$race->id.'/corrections','/races/'.$race->id.'/checkpoints','/races/'.$race->id.'/publication','/races/'.$race->id.'/participants'] as $url) $this->post($url,[])->assertForbidden();
        $this->put('/races/'.$race->id,[])->assertForbidden();
        $role->update(['permissions'=>[]]);
        $this->actingAs($official->fresh())->get('/races/'.$race->id.'/station')->assertForbidden();
        $this->post('/races/'.$race->id.'/timings',[])->assertForbidden();
        $admin=User::factory()->create(['role'=>UserRole::Admin,'access_role_id'=>$role->id]);
        $this->actingAs($admin)->get('/races/'.$other->id.'/station')->assertOk();
    }
    public function test_admin_can_assign_custom_roles_and_invalid_permissions_are_rejected(): void {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $role=AccessRole::create(['name'=>'Read only','permissions'=>[]]);
        $this->actingAs($admin)->post('/users',['name'=>'New official','email'=>'official@example.test','password'=>'long-password-for-test','delivery'=>'manual','role'=>'organizer','access_role_id'=>$role->id])->assertSessionHasNoErrors();
        $user=User::where('email','official@example.test')->firstOrFail();
        $this->assertSame($role->id,$user->access_role_id);
        $this->assertSame([],$user->effectivePermissions());
        $this->put('/users/'.$user->id,['name'=>$user->name,'email'=>$user->email,'role'=>'organizer','is_active'=>true,'access_role_id'=>null])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasPermission('timings.record'));
        $this->post('/admin/roles',['name'=>'Bad role','permissions'=>['users.manage']])->assertSessionHasErrors('permissions.0');
        $this->post('/admin/roles',['name'=>'Admin','permissions'=>[]])->assertSessionHasErrors('name');
        $this->post('/admin/roles',['name'=>'Old setup role','permissions'=>['races.create']])->assertSessionHasErrors('permissions.0');
        $this->put('/admin/roles/'.AccessRole::where('is_default',true)->value('id'),['name'=>'Official','permissions'=>[]])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasPermission('timings.record'));
        $this->assertTrue($admin->hasPermission('races.create'));
    }
}
