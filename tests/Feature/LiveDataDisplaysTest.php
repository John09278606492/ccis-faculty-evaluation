<?php

use App\Filament\Pages\DepartmentReport;
use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Livewire\PanelChrome;
use App\Livewire\WorkspaceContext;
use App\Models\Campus;
use App\Models\FormSubmission;
use App\Models\SchoolYear;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->offering->campus;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->admin = User::factory()->create(['is_student' => false]);
    $this->admin->assignRole('super_admin');
    foreach (['view_any_user', 'view_user', 'view_any_department', 'view_department', 'view_any_role', 'view_role'] as $name) {
        $this->admin->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->actingAs($this->admin);
});

it('updates navigation badges and tenant labels while preserving the active navigation item', function () {
    $url = DepartmentResource::getUrl('index', ['tenant' => $this->campus]);
    $sidebar = Livewire::test(PanelChrome::class, ['area' => 'sidebar', 'pageUrl' => $url])->assertSuccessful();
    $active = fn ($component) => collect($component->viewData('navigation'))->flatMap(fn ($group) => $group->getItems())->filter(fn ($item) => $item->isActive())->map(fn ($item) => $item->getUrl())->all();
    expect($active($sidebar))->toContain($url);
    $roleBadge = fn ($component) => collect($component->viewData('navigation'))->flatMap(fn ($group) => $group->getItems())->first(fn ($item) => str_contains($item->getUrl() ?? '', '/roles'))?->getBadge();
    $before = $roleBadge($sidebar);
    Role::create(['name' => 'new_live_role', 'guard_name' => 'web']);
    $this->campus->update(['name' => 'Updated campus label']);
    app()->forgetInstance(NavigationManager::class); // a fresh request-scoped manager
    $sidebar->call('$refresh')->assertSee('Updated Campus Label');
    expect($active($sidebar))->toContain($url);
    if ($before !== null) {
        expect((int) $roleBadge($sidebar))->toBe((int) $before + 1);
    }
});

it('rechecks navigation permissions on live updates', function () {
    $actor = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $actor->campuses()->attach($this->campus);
    $actor->givePermissionTo('view_any_department');
    $this->actingAs($actor);
    $sidebar = Livewire::test(PanelChrome::class, ['area' => 'sidebar', 'pageUrl' => DepartmentResource::getUrl('index', ['tenant' => $this->campus])]);
    $links = fn ($component) => collect($component->viewData('navigation'))->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->all();
    expect($links($sidebar))->toContain('Departments');
    $actor->revokePermissionTo('view_any_department');
    $this->actingAs($actor->fresh());
    app()->forgetInstance(NavigationManager::class);
    $sidebar->call('$refresh')->assertSuccessful();
    expect($links($sidebar))->not->toContain('Departments');
});

it('updates the topbar account label without displaying another campus', function () {
    $topbar = Livewire::test(PanelChrome::class, ['area' => 'topbar', 'pageUrl' => url('/'.$this->campus->id)]);
    $this->admin->update(['first_name' => 'Updated', 'middle_name' => null, 'last_name' => 'Account Label']);
    $topbar->call('$refresh')->assertSuccessful()->assertSee('Updated Account Label');
    $foreign = Campus::create(['name' => 'Unassigned private campus', 'slug' => 'private-campus', 'is_active' => true]);
    $actor = User::factory()->create(['is_student' => false]);
    $actor->campuses()->attach($this->campus);
    $this->actingAs($actor);
    Livewire::test(PanelChrome::class, ['area' => 'sidebar', 'pageUrl' => url('/'.$this->campus->id)])->assertDontSee($foreign->name);
});

it('reloads read-only staff details from the authorized record', function () {
    $subject = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $subject->campuses()->attach($this->campus);
    $view = Livewire::test(ViewUser::class, ['record' => $subject->id])->assertSee($subject->email);
    $subject->update(['email' => 'updated-display@example.test']);
    $view->call('refreshDisplayedRecord')->assertSee('updated-display@example.test')->assertDontSee($subject->password);
    $this->admin->revokePermissionTo('view_user');
    // Ordinary staff cannot use the refresh action to bypass revoked view access.
    $actor = User::factory()->create(['is_student' => false, 'instructor_department_id' => $subject->instructor_department_id]);
    $actor->campuses()->attach($this->campus);
    $actor->givePermissionTo('view_any_user', 'view_user');
    $this->actingAs($actor);
    $restricted = Livewire::test(ViewUser::class, ['record' => $subject->id]);
    $actor->revokePermissionTo('view_user');
    $restricted->call('refreshDisplayedRecord')->assertForbidden();
});

it('updates school-year warnings for the selected campus rather than a shared global banner', function () {
    SchoolYear::where('campus_id', $this->campus->id)->update(['is_current' => false]);
    $context = Livewire::test(WorkspaceContext::class)->assertSee('data-active-year-warning', false);
    $this->offering->semester->schoolYear->refresh()->update(['is_current' => true, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
    $context->call('$refresh')->assertDontSee('data-active-year-warning', false);
    $foreign = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    Filament::setTenant($foreign, isQuiet: true);
    Livewire::test(WorkspaceContext::class)->assertSee('data-active-year-warning', false);
});

it('refills disabled role details with newly saved values', function () {
    $role = Role::create(['name' => 'original_display_role', 'guard_name' => 'web', 'team_id' => $this->campus->id]);
    $view = Livewire::test(ViewRole::class, ['record' => $role->id])->assertFormSet(['name' => 'original_display_role']);
    $role->update(['name' => 'updated_display_role']);
    $view->call('refreshDisplayedRecord')->assertSuccessful()->assertFormSet(['name' => 'updated_display_role']);
});

it('replaces ignored chart canvases only when the computed department data changes', function () {
    FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id, 'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5]]);
    $page = Livewire::test(DepartmentReport::class);
    $before = $page->html();
    FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id, 'data' => ['teaching_quality' => 1]]);
    $page->call('$refresh')->assertSuccessful();
    $html = $page->html();
    expect($html)->toContain('wire:key="distribution-')->toContain('wire:key="trend-')->toContain('this.chart?.destroy()');
    preg_match('/wire:key="distribution-([^\"]+)/', $before, $oldKey);
    preg_match('/wire:key="distribution-([^\"]+)/', $html, $newKey);
    expect($newKey[1])->not->toBe($oldKey[1]);
});
