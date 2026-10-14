<?php

namespace Ernestdefoe\Backdrop\Tests\integration\api;

use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\LegacyImport;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class BackdropTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-backdrop');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'third', 'email' => 'third@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function image(int $width = 1200, int $height = 600, string $type = 'png'): UploadedFile
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 30, 120, 90));
        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.'.$type;
        $type === 'png' ? imagepng($gd, $path) : imagejpeg($gd, $path);
        $this->temp[] = $path;

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, "cover.$type", "image/$type");
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(int $userId, ?int $as, ?UploadedFile $file, array $fields = []): ResponseInterface
    {
        $request = $this->request('POST', "/api/users/$userId/backdrop", $as ? ['authenticatedAs' => $as] : []);

        if ($file) {
            $request = $request->withUploadedFiles(['cover' => $file]);
        }

        return $this->send($request->withParsedBody($fields));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coverIn(ResponseInterface $response): ?array
    {
        return json_decode((string) $response->getBody(), true)['data']['attributes']['backdrop'] ?? null;
    }

    private function disk(): string
    {
        return $this->tmpDir().'/public/assets/backdrop';
    }

    #[Test]
    public function a_member_sets_frames_and_removes_their_own_cover(): void
    {
        $response = $this->upload(2, 2, $this->image(), ['x' => '20', 'y' => '80', 'zoom' => '1.5']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $cover = $this->coverIn($response);
        $this->assertSame(['x' => 20, 'y' => 80, 'zoom' => 1.5], array_intersect_key($cover, ['x' => 0, 'y' => 0, 'zoom' => 0]));
        $this->assertStringEndsWith('.webp', $cover['url']);
        $this->assertStringEndsWith('-sm.webp', $cover['small']);

        $stored = User::find(2)->backdrop_cover;
        $this->assertFileExists($this->disk().'/'.$stored);
        $this->assertFileExists($this->disk().'/'.str_replace('.webp', '-sm.webp', $stored));

        // Reframing keeps the file.
        $response = $this->send($this->request('PATCH', '/api/users/2/backdrop', [
            'authenticatedAs' => 2,
            'json' => ['x' => 55, 'y' => 45, 'zoom' => 0.75],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0.75, (float) $this->coverIn($response)['zoom']);
        $this->assertSame($stored, User::find(2)->backdrop_cover);

        // A replacement deletes the old files.
        $this->upload(2, 2, $this->image(800, 400, 'jpeg'));
        $this->assertFileDoesNotExist($this->disk().'/'.$stored);
        $replacement = User::find(2)->backdrop_cover;

        $response = $this->send($this->request('DELETE', '/api/users/2/backdrop', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->coverIn($response));
        $this->assertNull(User::find(2)->backdrop_cover);
        $this->assertFileDoesNotExist($this->disk().'/'.$replacement);
    }

    #[Test]
    public function deleting_a_member_deletes_their_cover_files(): void
    {
        $this->upload(3, 3, $this->image());
        $stored = User::find(3)->backdrop_cover;
        $this->assertFileExists($this->disk().'/'.$stored);

        $response = $this->send($this->request('DELETE', '/api/users/3', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFileDoesNotExist($this->disk().'/'.$stored);
    }

    #[Test]
    public function large_images_are_stored_at_most_1920_wide(): void
    {
        $this->upload(2, 2, $this->image(3000, 1000));

        [$width] = getimagesize($this->disk().'/'.User::find(2)->backdrop_cover);
        $this->assertSame(1920, $width);
    }

    #[Test]
    public function members_cannot_change_someone_elses_cover_but_admins_can(): void
    {
        $this->assertSame(403, $this->upload(3, 2, $this->image())->getStatusCode());
        $this->assertNull(User::find(3)->backdrop_cover);

        $this->assertSame(200, $this->upload(3, 1, $this->image())->getStatusCode());
        $this->assertNotNull(User::find(3)->backdrop_cover);
    }

    #[Test]
    public function guests_are_refused(): void
    {
        // A real guest session, CSRF token included, so the refusal is the endpoint's.
        $request = $this->requestWithCsrfToken($this->request('POST', '/api/users/2/backdrop'))
            ->withUploadedFiles(['cover' => $this->image()]);

        $this->assertSame(401, $this->send($request)->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);
    }

    #[Test]
    public function the_permission_gates_your_own_cover(): void
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-backdrop.set')->delete();

        $this->assertSame(403, $this->upload(2, 2, $this->image())->getStatusCode());
    }

    #[Test]
    public function bad_input_is_refused(): void
    {
        $this->assertSame(422, $this->upload(2, 2, null)->getStatusCode());
        $this->assertSame(422, $this->upload(2, 2, $this->image(), ['zoom' => '9'])->getStatusCode());
        $this->assertSame(422, $this->upload(2, 2, $this->image(), ['x' => 'left'])->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.png';
        file_put_contents($path, '<?php echo "not an image";');
        $this->temp[] = $path;
        $fake = new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'cover.png', 'image/png');
        $this->assertSame(422, $this->upload(2, 2, $fake)->getStatusCode());

        // Nothing to reframe yet.
        $response = $this->send($this->request('PATCH', '/api/users/2/backdrop', ['authenticatedAs' => 2, 'json' => ['x' => 10]]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);
    }

    #[Test]
    public function members_are_limited_to_six_uploads_a_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(200, $this->upload(2, 2, $this->image(200, 100))->getStatusCode());
        }

        $this->assertSame(429, $this->upload(2, 2, $this->image(200, 100))->getStatusCode());
    }

    #[Test]
    public function listing_many_users_with_covers_adds_no_queries_per_user(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->database()->table('users')->where('id', $id)->update(['backdrop_cover' => "c$id.webp", 'backdrop_zoom' => 1.25]);
        }

        // The base TestCase fails the request if any query repeats per user.
        $response = $this->send($this->request('GET', '/api/users', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $users = json_decode((string) $response->getBody(), true)['data'];
        $this->assertCount(4, $users);

        foreach ($users as $user) {
            $this->assertStringEndsWith('c'.$user['id'].'.webp', $user['attributes']['backdrop']['url']);
            $this->assertSame($user['id'] === '2', $user['attributes']['canEditBackdrop']);
        }
    }

    #[Test]
    public function the_forum_publishes_its_settings_and_guests_see_no_edit_flag(): void
    {
        $this->database()->table('users')->where('id', 2)->update(['backdrop_cover' => 'https://cdn.example/x.jpg']);

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame(
            ['height' => 220, 'heightMobile' => 160, 'overlay' => 'gradient', 'onCards' => true, 'onDirectory' => true, 'default' => null],
            $forum['data']['attributes']['backdrop']
        );

        $user = json_decode((string) $this->send($this->request('GET', '/api/users/2'))->getBody(), true);
        $this->assertArrayNotHasKey('canEditBackdrop', $user['data']['attributes']);
        // An imported URL is served as it is, for both sizes.
        $this->assertSame('https://cdn.example/x.jpg', $user['data']['attributes']['backdrop']['small']);
    }

    #[Test]
    public function only_admins_manage_the_default_cover(): void
    {
        $post = fn (int $as) => $this->send(
            $this->request('POST', '/api/backdrop/default', ['authenticatedAs' => $as])
                ->withUploadedFiles(['cover' => $this->image()])
                ->withParsedBody(['zoom' => '0.6'])
        );

        $this->assertSame(403, $post(2)->getStatusCode());

        $response = $post(1);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0.6, json_decode((string) $response->getBody(), true)['data']['zoom']);

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame(0.6, $forum['data']['attributes']['backdrop']['default']['zoom']);

        $response = $this->send($this->request('DELETE', '/api/backdrop/default', ['authenticatedAs' => 1]));
        $this->assertNull(json_decode((string) $response->getBody(), true)['data']);
    }

    #[Test]
    public function covers_come_over_from_cover_studio_without_overwriting_anything(): void
    {
        $this->app();
        $schema = $this->database()->getSchemaBuilder();
        $schema->create('cover_studio_user_data', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('cover_file_id')->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->decimal('cover_focus_x', 5, 2)->default(50);
            $table->decimal('cover_focus_y', 5, 2)->default(50);
            $table->decimal('cover_zoom', 4, 2)->default(1);
        });

        $db = $this->database();
        $db->table('cover_studio_user_data')->insert([
            ['user_id' => 2, 'cover_file_id' => 7, 'cover_url' => 'https://cdn.example/two.jpg', 'cover_focus_x' => 70, 'cover_focus_y' => 30, 'cover_zoom' => 1.2],
            ['user_id' => 3, 'cover_file_id' => 8, 'cover_url' => 'https://cdn.example/three.jpg', 'cover_focus_x' => 10, 'cover_focus_y' => 10, 'cover_zoom' => 1],
            ['user_id' => 4, 'cover_file_id' => 9, 'cover_url' => 'javascript:alert(1)', 'cover_focus_x' => 50, 'cover_focus_y' => 50, 'cover_zoom' => 1],
        ]);
        $db->table('users')->where('id', 3)->update(['backdrop_cover' => 'mine.webp']);
        $db->table('group_permission')->insert(['group_id' => 4, 'permission' => 'tryhackx-cover-studio.setCover']);
        $db->table('settings')->insert(['key' => 'tryhackx-cover-studio.cover_height', 'value' => '300']);

        $import = $this->app()->getContainer()->make(LegacyImport::class);
        $this->assertSame(['coverStudio' => 1, 'sycho' => 0], $import->import());
        $this->assertSame(['coverStudio' => 0, 'sycho' => 0], $import->import());

        $two = $db->table('users')->find(2);
        $this->assertSame('https://cdn.example/two.jpg', $two->backdrop_cover);
        $this->assertEquals([70, 30, 1.2], [$two->backdrop_x, $two->backdrop_y, $two->backdrop_zoom]);
        $this->assertSame('mine.webp', $db->table('users')->find(3)->backdrop_cover);
        $this->assertNull($db->table('users')->find(4)->backdrop_cover);

        $this->assertTrue($db->table('group_permission')->where(['group_id' => 4, 'permission' => 'ernestdefoe-backdrop.set'])->exists());
        $this->assertSame('300', $db->table('settings')->where('key', 'ernestdefoe-backdrop.height')->value('value'));

        // A remote URL has no local file, so nothing is copied.
        $this->assertSame(0, $import->localize($this->app()->getContainer()->make(CoverStore::class)));
    }
}
