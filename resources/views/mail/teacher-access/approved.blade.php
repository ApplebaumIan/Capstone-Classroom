<x-mail::message>
# Teacher access approved

Hi {{ $user->name }},

Your request was approved. You can now create and manage classrooms in {{ config('app.name') }}.

<x-mail::button :url="$dashboardUrl">
Create a classroom
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
