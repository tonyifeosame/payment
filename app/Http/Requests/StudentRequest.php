<?php

namespace App\Http\Requests;

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a student. The school is the one bound to the route (already
 * proven to be the acting admin's by EnsureSchoolAdmin), never a request field.
 */
class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication and tenant matching are done by EnsureSchoolAdmin.
        return $this->route('school') instanceof School;
    }

    public function rules(): array
    {
        /** @var School $school */
        $school = $this->route('school');
        /** @var Student|null $student */
        $student = $this->route('student');

        $uniqueAdmission = Rule::unique('students', 'admission_number')
            ->where(fn ($q) => $q->where('school_id', $school->id));
        if ($student) {
            $uniqueAdmission->ignore($student->id);
        }

        // Once the school has set up its class ladder the class is chosen from it;
        // until then the legacy free-text class is still accepted, so a school that
        // has not configured classes yet can keep adding students.
        $hasLadder = ClassLevel::forSchool($school)->exists();
        $classRules = $hasLadder
            ? ['class_level_id' => [
                'required', 'integer',
                Rule::exists('class_levels', 'id')->where(fn ($q) => $q->where('school_id', $school->id)),
            ]]
            : ['class_name' => ['required', 'string', 'max:100']];

        return $classRules + [
            'full_name' => ['required', 'string', 'max:255'],
            'admission_number' => ['required', 'string', 'max:50', $uniqueAdmission],
            'status' => ['required', Rule::in(array_keys(Student::STATUS_LABELS))],
            'academic_session_id' => [
                'nullable', 'integer',
                Rule::exists('academic_sessions', 'id')->where(fn ($q) => $q->where('school_id', $school->id)),
            ],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** Normalise before validation so the unique check sees the stored form. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'admission_number' => Student::normalizeAdmissionNumber($this->input('admission_number')),
            'full_name' => trim((string) $this->input('full_name')),
            'class_name' => trim((string) $this->input('class_name')),
            'academic_session_id' => $this->input('academic_session_id') ?: null,
            'status' => $this->input('status') ?: Student::STATUS_ACTIVE,
        ]);
    }

    /**
     * The attributes to persist. When a level was chosen, class_name (the snapshot
     * the payment page and receipts read) is written from that level — never from
     * the request — so the two can not disagree.
     */
    public function studentAttributes(): array
    {
        $data = $this->validated();

        if (isset($data['class_level_id'])) {
            /** @var School $school */
            $school = $this->route('school');
            $level = ClassLevel::forSchool($school)->findOrFail((int) $data['class_level_id']);
            $data['class_level_id'] = $level->id;
            $data['class_name'] = $level->name;
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'admission_number.unique' => 'Another student at this school already has this admission number.',
            'academic_session_id.exists' => 'The selected session does not belong to this school.',
            'class_level_id.required' => 'Choose a class.',
            'class_level_id.exists' => 'Choose one of your own classes.',
        ];
    }
}
