@extends('pdf.layout')

@section('content')
    <h1>{{ $definition->title }}</h1>
    <div class="meta">
        Generated at: {{ now()->timezone(config('app.timezone'))->format('d M Y H:i') }}
        · Rows: {{ $records->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th class="row-number">#</th>
                @foreach ($definition->columns as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    <td class="row-number">{{ $loop->iteration }}</td>
                    @foreach (array_keys($definition->columns) as $path)
                        <td @class(['right' => in_array($path, $definition->monetary, true)])>
                            {{ $service->pdfValue($record, $path, $definition) ?? '—' }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($definition->columns) + 1 }}">No records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
