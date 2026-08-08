{{-- The post's generate-time hero render (fal → R2). Expects $image (URL or null). The image is
     produced when the draft is generated, so it is present all the way through review → publish —
     it does NOT wait for publish. Falls back to a quiet placeholder while nothing has rendered. --}}
@if (! empty($image))
    <img src="{{ $image }}" alt="" class="bc-thumb" loading="lazy" decoding="async">
@else
    <div class="bc-thumb-empty">No image rendered yet</div>
@endif
