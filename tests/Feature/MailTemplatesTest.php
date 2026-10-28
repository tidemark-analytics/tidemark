<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Mail\OrganizationAdminMail;
use App\Mail\OwnershipTransferMail;
use App\Mail\TeamMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The other tests fake mail, so a template that does not compile would only fail when a real person is invited: render every one here. */
class MailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_invitation_and_notice_email_renders_in_both_forms(): void
    {
        $dana = User::create(['name' => 'Dana Owner', 'email' => 'dana@example.com', 'password' => 'password']);
        $org = Organization::create(['owner_id' => $dana->id, 'name' => 'Foggy Mountain']);
        $site = Site::create(['domain' => 'example.com', 'organization_id' => $org->id]);
        $site->members()->attach($dana->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Marketing']);

        $site1 = (new InviteMail($site, $dana, 'admin', Invitation::issue('a@example.com', $dana, $site, 'admin')))->render();
        $site2 = (new InviteMail($site, $dana, 'viewer'))->render();
        $team1 = (new TeamMail($team, $dana, Invitation::issueForTeam('b@example.com', $dana, $team)))->render();
        $team2 = (new TeamMail($team, $dana))->render();
        $org1 = (new OrganizationAdminMail($org, $dana, Invitation::issueForOrganization('c@example.com', $dana, $org)))->render();
        $org2 = (new OrganizationAdminMail($org, $dana))->render();
        $transfer = (new OwnershipTransferMail($org, $dana))->render();

        $this->assertStringContainsString('Dana Owner invited you to example.com', $site1);
        $this->assertStringContainsString('Dana Owner gave you access to example.com', $site2);
        $this->assertStringContainsString('invited you to the Marketing team', $team1);
        $this->assertStringContainsString('added you to the Marketing team', $team2);
        $this->assertStringContainsString('invited you to help manage Foggy Mountain', $org1);
        $this->assertStringContainsString('made you an admin of Foggy Mountain', $org2);
        $this->assertStringContainsString('wants to make you the owner of Foggy Mountain', $transfer);
        foreach ([$site1, $team1, $org1] as $html) {
            $this->assertStringContainsString('/invite?token=', $html);
            $this->assertStringContainsString('expires in 72 hours', $html);
        }
    }
}
