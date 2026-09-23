<?php
namespace App\Http\Controllers;
use App\Models\AccessRole;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
class AccessRoleController extends Controller
{
    public function index() {
        return Inertia::render('Admin/Roles',[
            'roles'=>AccessRole::withCount('users')->orderByDesc('is_default')->orderBy('name')->get()->map(function($role) {
                if ($role->is_default) $role->users_count += \App\Models\User::where('role','organizer')->whereNull('access_role_id')->count();
                return $role;
            }), 'permissions'=>Permissions::catalogue(),
        ]);
    }
    private function data(Request $request, ?AccessRole $role=null): array {
        $request->merge(['name'=>trim((string)$request->input('name'))]);
        if (in_array(mb_strtolower($request->input('name')),['organizer (admin)','administrator','admin','athlete'],true)) throw ValidationException::withMessages(['name'=>'This name is reserved for a built-in role.']);
        $data=$request->validate([
            'name'=>['required','string','max:100',Rule::unique('access_roles')->ignore($role?->id),Rule::notIn(['Organizer (admin)','Administrator','Admin','Athlete'])],
            'description'=>['nullable','string','max:500'],
            'permissions'=>['present','array'], 'permissions.*'=>['string','distinct',Rule::in(Permissions::all())],
        ]);
        if ($role?->is_default) $data['name']='Official';
        return $data;
    }
    public function store(Request $request) {
        AccessRole::create($this->data($request));
        return back()->with('success','Role created. Assign it from Users → Edit user.');
    }
    public function update(Request $request, AccessRole $accessRole) {
        $accessRole->update($this->data($request,$accessRole));
        return back()->with('success','Role updated. Permissions apply on each user’s next request.');
    }
    public function destroy(AccessRole $accessRole) {
        DB::transaction(function() use($accessRole) {
            $role=AccessRole::lockForUpdate()->findOrFail($accessRole->id);
            if ($role->is_default || $role->users()->exists()) throw ValidationException::withMessages(['role'=>'Reassign all users before deleting a custom role. The default Official role cannot be deleted.']);
            $role->delete();
        });
        return back()->with('success','Role deleted.');
    }
}
