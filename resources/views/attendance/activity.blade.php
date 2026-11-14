@extends('layouts.app')
@section('title','Detalle de actividad')
@section('content')
<section class="card"><p class="section-eyebrow">{{ $activity->registered_at->timezone('America/Guayaquil')->format('d/m/Y H:i:s') }}</p><p class="mt-4 whitespace-pre-line">{{ $activity->description }}</p>@if($activity->evidence)<img class="mt-5 max-h-[40rem] w-full rounded-sm object-contain" src="{{ route('attendance.photo',$activity->evidence) }}" alt="Evidencia fotográfica de la actividad">@else<p class="mt-4 text-sm text-red-800">La evidencia no está disponible.</p>@endif<a class="btn-secondary mt-5" href="{{ route('attendance.show',$activity->interval->day) }}">Volver a la jornada</a></section>
@endsection
