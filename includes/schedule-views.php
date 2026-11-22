<?php
declare(strict_types=1);

function az_render_schedule_view(string $page): void
{
    $copy = [
        'calendar' => ['MAKE TIME FOR THE TEAM', 'The calendar', 'Find the next shoot, event, or class. Reserve a spot and keep your commitments together.'],
        'classes' => ['LEARN. PRACTICE. SHOW UP.', 'Classes', 'Explore the class catalog and join individual sessions on the private calendar.'],
        'appointments' => ['A LITTLE TIME, JUST FOR YOU', 'Appointments', 'Choose a professional, find an available time, and manage your bookings.'],
    ][$page];
    ?>
<div id="schedule-toast" class="toast" role="status" aria-live="polite"></div>
<main class="workspace schedule-workspace">
    <section class="page-heading"><div><p class="eyebrow"><?= az_h($copy[0]) ?></p><h1><?= az_h($copy[1]) ?><span class="accent">.</span></h1><p class="page-intro"><?= az_h($copy[2]) ?></p><p class="schedule-zone">Members only <span aria-hidden="true">·</span> America/Phoenix (Arizona time)</p></div><button id="schedule-new" class="button button--primary" type="button" hidden><?= $page === 'classes' ? 'New class' : 'New event' ?></button></section>
    <div id="schedule-error" class="schedule-notice is-error" role="alert" hidden></div>
    <div id="schedule-loading" class="schedule-notice" role="status">Loading your schedule…</div>
    <section id="schedule-detail" class="schedule-detail" aria-live="polite" hidden></section>
    <?php if ($page === 'calendar'): ?>
    <section id="calendar-main" hidden>
        <div class="schedule-toolbar"><div class="month-controls"><button id="calendar-prev" type="button" class="button button--small">Previous</button><h2 id="calendar-month"></h2><button id="calendar-next" type="button" class="button button--small">Next</button><button id="calendar-today" type="button" class="button button--quiet">Today</button></div><div class="schedule-filters"><label class="field compact-field"><span class="sr-only">Event type</span><select id="calendar-type"><option value="all">Events &amp; classes</option><option value="event">Events</option><option value="class">Classes</option></select></label><label class="checkbox-field"><input id="calendar-mine" type="checkbox">My registrations</label><button id="calendar-view" type="button" class="button button--small" aria-pressed="false">Agenda view</button></div></div>
        <div id="calendar-filter-note" class="schedule-filter-note" hidden></div><div id="calendar-metrics" class="schedule-metrics"></div>
        <div id="calendar-grid" class="calendar-grid" aria-label="Month calendar"></div>
        <section class="agenda-section"><div class="schedule-section-heading"><div><p class="eyebrow">WHAT’S COMING UP</p><h2 id="agenda-title">This month</h2></div><button id="calendar-clear-day" type="button" class="button button--small" hidden>Show whole month</button></div><div id="calendar-agenda" class="schedule-list"></div></section>
    </section>
    <?php elseif ($page === 'classes'): ?>
    <section id="classes-main" hidden><div class="schedule-toolbar"><label class="field schedule-search"><span class="sr-only">Search classes</span><input id="class-search" type="search" placeholder="Search classes or instructors…"></label><label id="class-archive-wrap" class="field compact-field" hidden><span class="sr-only">Class status</span><select id="class-status"><option value="active">Active classes</option><option value="archived">Archived classes</option><option value="all">All classes</option></select></label></div><div id="class-list" class="schedule-card-grid"></div></section>
    <?php else: ?>
    <nav id="appointment-tabs" class="schedule-tabs" aria-label="Appointment sections" hidden><button type="button" data-appointment-tab="book" aria-current="page">Book an appointment</button><button type="button" data-appointment-tab="mine">My appointments</button><button type="button" data-appointment-tab="workspace" id="professional-tab" hidden>Professional workspace</button></nav>
    <section id="appointment-book" hidden><div class="booking-layout"><section class="panel schedule-panel"><p class="eyebrow">CHOOSE YOUR SESSION</p><h2>Find a time</h2><div class="schedule-form-grid"><label class="field">Professional<select id="booking-professional"><option value="">All professionals</option></select></label><label class="field">Appointment type<select id="booking-type"><option value="">Choose an appointment</option></select></label></div><div id="booking-type-detail" class="schedule-help"></div><form id="booking-form"><label class="field">Appointment date<input id="booking-date" type="date" required></label><div id="booking-slots" class="slot-list" aria-live="polite"><p class="muted">Choose an appointment type and date to see available times.</p></div><label class="field">Booking notes <span class="optional">Optional</span><textarea id="booking-notes" maxlength="4000" rows="3" placeholder="Anything your professional should know before the session."></textarea></label><button id="booking-submit" class="button button--primary" type="submit" disabled>Book appointment</button></form></section><aside class="panel schedule-panel booking-aside"><p class="eyebrow">YOUR RESERVATION</p><h2>A time that’s available.</h2><p>Available times account for working hours, blocked time, and existing appointments. Your registered events and classes are checked too.</p><p>All times are shown in Arizona time. Keep the booking confirmation for your plans.</p><div id="booking-confirmation" hidden></div></aside></div></section>
    <section id="appointment-mine" hidden><div class="schedule-toolbar"><h2>Your appointments</h2><label class="field compact-field"><span class="sr-only">Appointment status</span><select id="my-appointment-status"><option value="upcoming">Upcoming</option><option value="past">Past</option><option value="all">All appointments</option></select></label></div><div id="my-appointment-list" class="schedule-list"></div></section>
    <section id="appointment-workspace" hidden><div class="schedule-toolbar"><div><p class="eyebrow">YOUR BOOKABLE TIME</p><h2>Professional workspace</h2></div><label class="field"><span>Professional</span><select id="workspace-professional"></select></label></div><div id="workspace-content"></div></section>
    <?php endif; ?>
</main>
<?php
}
