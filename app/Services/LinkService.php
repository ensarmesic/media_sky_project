<?php

namespace App\Services;

use App\Models\Link;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Vanjski linkovi na stavci, postupku i projektu (samo http/https). */
class LinkService
{
    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        return [
            "{$prefix}url.url" => 'Unesite ispravan link koji počinje s http:// ili https://.',
            "{$prefix}url.required" => 'Unesite link.',
        ];
    }

    /** Ko smije dodavati/mijenjati linkove (isto pravilo kao za dokumente). */
    public static function canManage(User $user, Model $owner): bool
    {
        return DocumentService::canUpload($user, $owner);
    }

    /** @param  array{url: string, title?: ?string}  $data */
    public function create(Model $owner, User $user, array $data): Link
    {
        abort_unless(self::canManage($user, $owner), 403);
        validator($data, self::rules(), self::messages())->validate();

        $link = $owner->links()->create(['user_id' => $user->id, 'url' => trim($data['url']), 'title' => filled($data['title'] ?? null) ? trim($data['title']) : null]);
        $this->log($owner, $user, 'link.added', 'Dodan link: '.$link->displayTitle(), $link);

        return $link;
    }

    /** @param  array{url: string, title?: ?string}  $data */
    public function update(Link $link, User $user, array $data): Link
    {
        abort_unless(self::canManage($user, $link->linkable), 403);
        validator($data, self::rules(), self::messages())->validate();

        $link->update(['url' => trim($data['url']), 'title' => filled($data['title'] ?? null) ? trim($data['title']) : null]);
        $this->log($link->linkable, $user, 'link.updated', 'Izmijenjen link: '.$link->displayTitle(), $link);

        return $link;
    }

    public function delete(Link $link, User $user): void
    {
        abort_unless(self::canManage($user, $link->linkable), 403);
        $this->log($link->linkable, $user, 'link.deleted', 'Obrisan link: '.$link->displayTitle(), $link);
        $link->delete();
    }

    private function log(Model $owner, User $user, string $event, string $title, Link $link): void
    {
        $project = Project::owning($owner);
        if ($project !== null) {
            app(ProjectActivityService::class)->record($project, $event, $title, $user, $owner, $link->url, ['link_id' => $link->id]);
        }
    }
}
