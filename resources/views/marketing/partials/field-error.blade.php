{{-- Inline validation message for one field. The id is referenced by the input's
     aria-describedby so screen readers announce it with the field. --}}
@error($field)
    <p id="{{ $field }}-error" class="field-error" role="alert">{{ $message }}</p>
@enderror
