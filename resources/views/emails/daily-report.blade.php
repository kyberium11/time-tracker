<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Time Report - {{ $date }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1f2937;
            margin-top: 0;
            font-size: 24px;
            border-bottom: 2px solid #6366f1;
            padding-bottom: 10px;
        }
        .summary {
            display: flex;
            gap: 20px;
            margin: 30px 0;
        }
        .summary-card {
            flex: 1;
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #6366f1;
        }
        .summary-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .summary-value {
            font-size: 28px;
            font-weight: bold;
            color: #111827;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: #ffffff;
        }
        thead {
            background-color: #f9fafb;
        }
        th {
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }
        th.text-right {
            text-align: right;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        td.text-right {
            text-align: right;
        }
        tr:hover {
            background-color: #f9fafb;
        }
        .task-name {
            font-weight: 500;
            color: #111827;
        }
        .duration {
            font-weight: 600;
            color: #111827;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Daily Time Report</h1>
        <p style="color: #6b7280; margin-bottom: 20px;">
            <strong>Date:</strong> {{ \Carbon\Carbon::parse($date)->format('F d, Y') }}<br>
            <strong>User:</strong> {{ $userName }}
        </p>

        <div class="summary">
            <div class="summary-card">
                <div class="summary-label">Total Work Hours</div>
                <div class="summary-value">
                    @php
                        $workTotalSeconds = (int)($totalWorkHours * 3600);
                        $workH = floor($workTotalSeconds / 3600);
                        $workM = floor(($workTotalSeconds % 3600) / 60);
                        $workS = $workTotalSeconds % 60;
                    @endphp
                    {{ sprintf('%02d:%02d:%02d', $workH, $workM, $workS) }}
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Total Task Hours</div>
                <div class="summary-value">
                    @php
                        $taskTotalSeconds = (int)($totalTaskHours * 3600);
                        $taskH = floor($taskTotalSeconds / 3600);
                        $taskM = floor(($taskTotalSeconds % 3600) / 60);
                        $taskS = $taskTotalSeconds % 60;
                    @endphp
                    {{ sprintf('%02d:%02d:%02d', $taskH, $taskM, $taskS) }}
                </div>
            </div>
        </div>

        @if(count($entries) > 0)
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th class="text-right">Start Time</th>
                    <th class="text-right">End Time</th>
                    <th class="text-right">Duration</th>
                    <th class="text-right">Break Duration</th>
                    <th class="text-right">Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entries as $entry)
                <tr>
                    <td class="task-name">{{ $entry['name'] }}</td>
                    <td class="text-right">
                        @if($entry['start'])
                            {{ \Carbon\Carbon::parse($entry['start'])->format('h:i A') }}
                        @else
                            --
                        @endif
                    </td>
                    <td class="text-right">
                        @if($entry['end'])
                            {{ \Carbon\Carbon::parse($entry['end'])->format('h:i A') }}
                        @else
                            --
                        @endif
                    </td>
                    <td class="text-right duration">
                        @php
                            $hours = floor($entry['duration_seconds'] / 3600);
                            $minutes = floor(($entry['duration_seconds'] % 3600) / 60);
                            $seconds = $entry['duration_seconds'] % 60;
                        @endphp
                        {{ sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds) }}
                    </td>
                    <td class="text-right">
                        @php
                            $breakHours = floor($entry['break_duration_seconds'] / 3600);
                            $breakMinutes = floor(($entry['break_duration_seconds'] % 3600) / 60);
                            $breakSeconds = $entry['break_duration_seconds'] % 60;
                        @endphp
                        {{ sprintf('%02d:%02d:%02d', $breakHours, $breakMinutes, $breakSeconds) }}
                    </td>
                    <td class="text-right">{{ $entry['notes'] ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p style="color: #6b7280; text-align: center; padding: 40px;">No entries for this day.</p>
        @endif

        <div class="footer">
            <p>This is an automated daily time report from Time Tracker.</p>
            <p>Generated on {{ \Carbon\Carbon::now()->format('F d, Y \a\t h:i A') }}</p>
        </div>
    </div>
</body>
</html>

