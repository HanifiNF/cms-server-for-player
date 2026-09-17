<?php
namespace App\Libraries;

final class ExternalJobMetadata
{
    public function validate(array $input): array
    {
        $result = []; $errors = [];
        foreach (['title'=>255,'synopsis'=>5000,'language'=>80,'subtitles'=>160,'distributor_company'=>180] as $field=>$limit) {
            $value = is_scalar($input[$field] ?? '') ? trim((string) ($input[$field] ?? '')) : '';
            if (mb_strlen($value) > $limit) $errors[$field] = "Maximum {$limit} characters.";
            $result[$field] = $value === '' ? null : $value;
        }
        if (! $result['title']) $errors['title'] = 'Title is required for an external encryption job.';
        $result['asset_type'] = $input['asset_type'] ?? '';
        if (! in_array($result['asset_type'], AssetTaxonomyService::TYPES, true)) $errors['asset_type'] = 'Choose a valid asset type.';
        try { $result['genre_ids'] = (new AssetTaxonomyService())->validateGenreIds($input['genre_ids'] ?? []); }
        catch (\RuntimeException $e) { $errors['genre_ids'] = $e->getMessage(); }
        $rating = $input['age_rating'] ?? '';
        if (! in_array($rating, ['', 'SU','13+','17+','21+'], true)) $errors['age_rating'] = 'Choose a valid age rating.';
        $result['age_rating'] = $rating === '' ? null : $rating;
        $year = $input['production_year'] ?? '';
        if ($year !== '' && (filter_var($year, FILTER_VALIDATE_INT) === false || (int) $year < 1888 || (int) $year > (int) date('Y') + 2)) $errors['production_year'] = 'Enter a valid production year.';
        $result['production_year'] = $year === '' ? null : (int) $year;
        foreach (['release_date', 'expires_on'] as $field) {
            $value = $input[$field] ?? '';
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($value !== '' && (! $date || $date->format('Y-m-d') !== $value)) $errors[$field] = 'Enter a valid date.';
            if ($field === 'expires_on' && $date && $value < date('Y-m-d')) $errors[$field] = 'Valid until must be today or later.';
            $result[$field] = $value === '' ? null : $value;
        }
        if ($errors) throw new ExternalJobValidationException($errors);
        return $result;
    }

    public function poster(?object $poster): array
    {
        if ($poster === null || $poster->getError() === UPLOAD_ERR_NO_FILE) return [];
        $fail = static function (string $message): never { throw new ExternalJobValidationException(['poster'=>$message]); };
        if (! $poster->isValid() || $poster->hasMoved()) $fail('The poster upload is invalid.');
        if ($poster->getSize() > 10485760) $fail('Poster size may not exceed 10 MB.');
        $name = basename($poster->getClientName()); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $poster->getMimeType(); $image = @getimagesize($poster->getTempName());
        $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        if (($types[$ext] ?? null) !== $mime || ! $image || ($image['mime'] ?? '') !== $mime) $fail('Poster must be a valid JPG, PNG, or WebP image.');
        return ['filename'=>$name,'extension'=>$ext === 'jpeg' ? 'jpg' : $ext,'mime_type'=>$mime];
    }
}
