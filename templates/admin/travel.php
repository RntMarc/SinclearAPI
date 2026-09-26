<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">

<div class="page-header">
    <div>
        <h1>Reisen & Events</h1>
        <div class="subtitle">Reisen und Events erstellen, bearbeiten und löschen</div>
    </div>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Reisen</h2>
        <button class="btn btn-primary" onclick="showCreateTripForm()">+ Neue Reise</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Beschreibung</th>
                <th>Zeitraum</th>
                <th>Tickets</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{tripRows}}
        </tbody>
    </table>
</div>

<div class="card">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Events</h2>
        <button class="btn btn-primary" onclick="showCreateEventForm()">+ Neues Event</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Beschreibung</th>
                <th>Reise</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{eventRows}}
        </tbody>
    </table>
</div>

<!-- Create Trip Form -->
<div id="createTripForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Neue Reise erstellen</h2>
    <form id="newTripForm" onsubmit="submitCreateTrip(event)">
        <div class="form-group">
            <label for="newTripName">Name *</label>
            <input type="text" id="newTripName" name="name" placeholder="z. B. Sommerurlaub 2025" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="newTripDescription">Beschreibung</label>
            <textarea id="newTripDescription" name="description" placeholder="Reisebeschreibung"></textarea>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="newTripAllDay" checked onchange="toggleTripAllDay()">
                Ganztägig
            </label>
        </div>
        <div class="form-group">
            <label for="newTripTimezone">Zeitzone</label>
            <select id="newTripTimezone" name="timezone">{{timezoneOptions}}</select>
        </div>
        <div id="newTripDayFields">
            <div class="form-row">
                <div class="form-group">
                    <label for="newTripStartDate">Start-Datum *</label>
                    <input type="date" id="newTripStartDate" name="startDate">
                </div>
                <div class="form-group">
                    <label for="newTripEndDate">End-Datum *</label>
                    <input type="date" id="newTripEndDate" name="endDate">
                </div>
            </div>
        </div>
        <div id="newTripTimeFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="newTripStartAt">Beginn (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="newTripStartAt" name="startAt">
                </div>
                <div class="form-group">
                    <label for="newTripEndAt">Ende (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="newTripEndAt" name="endAt">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="newTripHastickets" onchange="toggleTripTickets()">
                Tickets vorhanden
            </label>
        </div>
        <div id="newTripTicketFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="newTripTicket">Ticket-Info</label>
                    <input type="text" id="newTripTicket" name="ticket" placeholder="z. B. Ticket-URL oder Code">
                </div>
                <div class="form-group">
                    <label for="newTripTicketUrl">Ticket-URL</label>
                    <input type="url" id="newTripTicketUrl" name="ticketUrl" placeholder="https://example.com/ticket">
                </div>
            </div>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Erstellen</button>
            <button type="button" class="btn" onclick="hideCreateTripForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Edit Trip Form -->
<div id="editTripForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Reise bearbeiten</h2>
    <form id="editTripFormEl" onsubmit="submitEditTrip(event)">
        <input type="hidden" id="editTripId">
        <div class="form-group">
            <label for="editTripName">Name *</label>
            <input type="text" id="editTripName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="editTripDescription">Beschreibung</label>
            <textarea id="editTripDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editTripAllDay" onchange="toggleEditTripAllDay()">
                Ganztägig
            </label>
        </div>
        <div class="form-group">
            <label for="editTripTimezone">Zeitzone</label>
            <select id="editTripTimezone" name="timezone">{{timezoneOptions}}</select>
        </div>
        <div id="editTripDayFields">
            <div class="form-row">
                <div class="form-group">
                    <label for="editTripStartDate">Start-Datum *</label>
                    <input type="date" id="editTripStartDate" name="startDate">
                </div>
                <div class="form-group">
                    <label for="editTripEndDate">End-Datum *</label>
                    <input type="date" id="editTripEndDate" name="endDate">
                </div>
            </div>
        </div>
        <div id="editTripTimeFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="editTripStartAt">Beginn (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="editTripStartAt" name="startAt">
                </div>
                <div class="form-group">
                    <label for="editTripEndAt">Ende (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="editTripEndAt" name="endAt">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editTripHastickets" onchange="toggleEditTripTickets()">
                Tickets vorhanden
            </label>
        </div>
        <div id="editTripTicketFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="editTripTicket">Ticket-Info</label>
                    <input type="text" id="editTripTicket" name="ticket">
                </div>
                <div class="form-group">
                    <label for="editTripTicketUrl">Ticket-URL</label>
                    <input type="url" id="editTripTicketUrl" name="ticketUrl">
                </div>
            </div>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="hideEditTripForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Create Event Form -->
<div id="createEventForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Neues Event erstellen</h2>
    <form id="newEventForm" onsubmit="submitCreateEvent(event)">
        <div class="form-group">
            <label for="newEventName">Name *</label>
            <input type="text" id="newEventName" name="name" placeholder="z. B. Konzert Berlin" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="newEventDescription">Beschreibung</label>
            <textarea id="newEventDescription" name="description" placeholder="Event-Beschreibung"></textarea>
        </div>
        <div class="form-group">
            <label for="newEventTrip">Reise (optional – leer lassen für Standalone-Event)</label>
            <select id="newEventTrip" name="trip">
                {{tripOptions}}
            </select>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="newEventAllDay" onchange="toggleEventAllDay()">
                Ganztägig
            </label>
        </div>
        <div class="form-group">
            <label for="newEventTimezone">Zeitzone</label>
            <select id="newEventTimezone" name="timezone">{{timezoneOptions}}</select>
        </div>
        <div id="newEventDayFields">
            <div class="form-row">
                <div class="form-group">
                    <label for="newEventStartDate">Start-Datum *</label>
                    <input type="date" id="newEventStartDate" name="startDate">
                </div>
                <div class="form-group">
                    <label for="newEventEndDate">End-Datum *</label>
                    <input type="date" id="newEventEndDate" name="endDate">
                </div>
            </div>
        </div>
        <div id="newEventTimeFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="newEventStartAt">Beginn (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="newEventStartAt" name="startAt">
                </div>
                <div class="form-group">
                    <label for="newEventEndAt">Ende (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="newEventEndAt" name="endAt">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="newEventHastickets" onchange="toggleEventTickets()">
                Tickets vorhanden
            </label>
        </div>
        <div id="newEventTicketFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="newEventTicket">Ticket-Info</label>
                    <input type="text" id="newEventTicket" name="ticket" placeholder="z. B. Ticket-Code">
                </div>
                <div class="form-group">
                    <label for="newEventTicketUrl">Ticket-URL</label>
                    <input type="url" id="newEventTicketUrl" name="ticketUrl" placeholder="https://example.com/ticket">
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newEventUrl">Event-URL</label>
                <input type="url" id="newEventUrl" name="url" placeholder="https://example.com/event">
            </div>
            <div class="form-group">
                <label>Banner-Bild (3.5:1, max. 500 KB)</label>
                <input type="file" id="newEventImageFile" accept="image/jpeg,image/png,image/webp" onchange="handleImageFile(this, 'newEventImage', 'newEventImagePreview')" style="padding:0.4rem 0;">
                <input type="hidden" id="newEventImage" name="image">
                <div id="newEventImagePreview" style="margin-top:0.5rem;"></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newEventOrganizer">Veranstalter</label>
                <input type="text" id="newEventOrganizer" name="organizer" placeholder="z. B. Eventagentur GmbH">
            </div>
            <div class="form-group">
                <label for="newEventAddress">Adresse</label>
                <input type="text" id="newEventAddress" name="address" placeholder="Straße, Ort">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newEventLatitude">Breitengrad</label>
                <input type="number" step="any" id="newEventLatitude" name="latitude" placeholder="52.5200">
            </div>
            <div class="form-group">
                <label for="newEventLongitude">Längengrad</label>
                <input type="number" step="any" id="newEventLongitude" name="longitude" placeholder="13.4050">
            </div>
        </div>
        <div class="form-group">
            <label for="newEventOSMID">OpenStreetMap ID</label>
            <input type="number" id="newEventOSMID" name="OSMID" placeholder="z. B. 123456789">
        </div>
        <div class="form-group">
            <label for="newEventCitySlug">City-Slug (optional)</label>
            <input type="text" id="newEventCitySlug" name="citySlug" placeholder="z. B. berlin, muenchen">
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Erstellen</button>
            <button type="button" class="btn" onclick="hideCreateEventForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Edit Event Form -->
<div id="editEventForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Event bearbeiten</h2>
    <form id="editEventFormEl" onsubmit="submitEditEvent(event)">
        <input type="hidden" id="editEventId">
        <div class="form-group">
            <label for="editEventName">Name *</label>
            <input type="text" id="editEventName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="editEventDescription">Beschreibung</label>
            <textarea id="editEventDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label for="editEventTrip">Reise</label>
            <select id="editEventTrip" name="trip">
                {{tripOptions}}
            </select>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editEventAllDay" onchange="toggleEditEventAllDay()">
                Ganztägig
            </label>
        </div>
        <div class="form-group">
            <label for="editEventTimezone">Zeitzone</label>
            <select id="editEventTimezone" name="timezone">{{timezoneOptions}}</select>
        </div>
        <div id="editEventDayFields">
            <div class="form-row">
                <div class="form-group">
                    <label for="editEventStartDate">Start-Datum *</label>
                    <input type="date" id="editEventStartDate" name="startDate">
                </div>
                <div class="form-group">
                    <label for="editEventEndDate">End-Datum *</label>
                    <input type="date" id="editEventEndDate" name="endDate">
                </div>
            </div>
        </div>
        <div id="editEventTimeFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="editEventStartAt">Beginn (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="editEventStartAt" name="startAt">
                </div>
                <div class="form-group">
                    <label for="editEventEndAt">Ende (Wandzeit in Zeitzone) *</label>
                    <input type="datetime-local" id="editEventEndAt" name="endAt">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editEventHastickets" onchange="toggleEditEventTickets()">
                Tickets vorhanden
            </label>
        </div>
        <div id="editEventTicketFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="editEventTicket">Ticket-Info</label>
                    <input type="text" id="editEventTicket" name="ticket">
                </div>
                <div class="form-group">
                    <label for="editEventTicketUrl">Ticket-URL</label>
                    <input type="url" id="editEventTicketUrl" name="ticketUrl">
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventUrl">Event-URL</label>
                <input type="url" id="editEventUrl" name="url">
            </div>
            <div class="form-group">
                <label>Banner-Bild (3.5:1, max. 500 KB)</label>
                <input type="file" id="editEventImageFile" accept="image/jpeg,image/png,image/webp" onchange="handleImageFile(this, 'editEventImage', 'editEventImagePreview')" style="padding:0.4rem 0;">
                <input type="hidden" id="editEventImage" name="image">
                <div id="editEventImagePreview" style="margin-top:0.5rem;"></div>
                <button type="button" class="btn btn-sm btn-danger" id="removeEditEventImage" onclick="removeEventImage('editEventImage', 'editEventImagePreview')" style="margin-top:0.4rem;display:none;">Bild entfernen</button>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventOrganizer">Veranstalter</label>
                <input type="text" id="editEventOrganizer" name="organizer">
            </div>
            <div class="form-group">
                <label for="editEventAddress">Adresse</label>
                <input type="text" id="editEventAddress" name="address">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventLatitude">Breitengrad</label>
                <input type="number" step="any" id="editEventLatitude" name="latitude">
            </div>
            <div class="form-group">
                <label for="editEventLongitude">Längengrad</label>
                <input type="number" step="any" id="editEventLongitude" name="longitude">
            </div>
        </div>
        <div class="form-group">
            <label for="editEventOSMID">OpenStreetMap ID</label>
            <input type="number" id="editEventOSMID" name="OSMID">
        </div>
        <div class="form-group">
            <label for="editEventCitySlug">City-Slug (optional)</label>
            <input type="text" id="editEventCitySlug" name="citySlug" placeholder="z. B. berlin, muenchen">
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="hideEditEventForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Crop Modal -->
<div id="cropModal" class="modal" onclick="if(event.target===this)cancelCrop()">
    <div class="card" style="max-width:700px;">
        <h2 id="cropModalTitle" style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Bild zuschneiden (3.5:1)</h2>
        <div style="max-height:400px;overflow:hidden;margin-bottom:1rem;">
            <img id="cropImage" src="" style="max-width:100%;display:block;">
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="button" class="btn btn-success" onclick="confirmCrop()">Zuschneiden & Übernehmen</button>
            <button type="button" class="btn" onclick="cancelCrop()">Abbrechen</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
    const eventsData = {{eventsData}};
    const tripsData = {{tripsData}};
    let cropper = null;
    let cropResolve = null;

    // Trip utilities
    function showCreateTripForm() {
        hideEditTripForm(); hideCreateEventForm(); hideEditEventForm();
        document.getElementById('createTripForm').style.display = 'block';
        document.getElementById('newTripName').focus();
    }
    function hideCreateTripForm() {
        document.getElementById('createTripForm').style.display = 'none';
        document.getElementById('newTripForm').reset();
        document.getElementById('newTripTicketFields').style.display = 'none';
    }
    function showEditTripForm() {
        hideCreateTripForm(); hideCreateEventForm(); hideEditEventForm();
        document.getElementById('editTripForm').style.display = 'block';
    }
    function hideEditTripForm() {
        document.getElementById('editTripForm').style.display = 'none';
        document.getElementById('editTripFormEl').reset();
        document.getElementById('editTripTicketFields').style.display = 'none';
    }
    function toggleTripTickets() {
        document.getElementById('newTripTicketFields').style.display =
            document.getElementById('newTripHastickets').checked ? 'block' : 'none';
    }
    function toggleEditTripTickets() {
        document.getElementById('editTripTicketFields').style.display =
            document.getElementById('editTripHastickets').checked ? 'block' : 'none';
    }

    // All-Day toggles
    function toggleTripAllDay() {
        const allDay = document.getElementById('newTripAllDay').checked;
        document.getElementById('newTripTimeFields').style.display = allDay ? 'none' : 'block';
        document.getElementById('newTripDayFields').style.display = allDay ? 'block' : 'none';
    }
    function toggleEditTripAllDay() {
        const allDay = document.getElementById('editTripAllDay').checked;
        document.getElementById('editTripTimeFields').style.display = allDay ? 'none' : 'block';
        document.getElementById('editTripDayFields').style.display = allDay ? 'block' : 'none';
    }

    // Event utilities
    function showCreateEventForm() {
        hideCreateTripForm(); hideEditTripForm(); hideEditEventForm();
        document.getElementById('createEventForm').style.display = 'block';
        document.getElementById('newEventName').focus();
    }
    function hideCreateEventForm() {
        document.getElementById('createEventForm').style.display = 'none';
        document.getElementById('newEventForm').reset();
        document.getElementById('newEventTicketFields').style.display = 'none';
        clearImagePreview('newEventImage', 'newEventImagePreview');
    }
    function showEditEventForm() {
        hideCreateTripForm(); hideEditTripForm(); hideCreateEventForm();
        document.getElementById('editEventForm').style.display = 'block';
    }
    function hideEditEventForm() {
        document.getElementById('editEventForm').style.display = 'none';
        document.getElementById('editEventFormEl').reset();
        document.getElementById('editEventTicketFields').style.display = 'none';
        clearImagePreview('editEventImage', 'editEventImagePreview');
    }
    function toggleEventTickets() {
        document.getElementById('newEventTicketFields').style.display =
            document.getElementById('newEventHastickets').checked ? 'block' : 'none';
    }
    function toggleEditEventTickets() {
        document.getElementById('editEventTicketFields').style.display =
            document.getElementById('editEventHastickets').checked ? 'block' : 'none';
    }

    // Event All-Day toggles
    function toggleEventAllDay() {
        const allDay = document.getElementById('newEventAllDay').checked;
        document.getElementById('newEventTimeFields').style.display = allDay ? 'none' : 'block';
        document.getElementById('newEventDayFields').style.display = allDay ? 'block' : 'none';
    }
    function toggleEditEventAllDay() {
        const allDay = document.getElementById('editEventAllDay').checked;
        document.getElementById('editEventTimeFields').style.display = allDay ? 'none' : 'block';
        document.getElementById('editEventDayFields').style.display = allDay ? 'block' : 'none';
    }

    // Image handling
    const BANNER_MAX_BYTES = 500 * 1024;
    let cropAspectRatio = 3.5 / 1;
    let cropOutputWidth = 1750;
    let cropOutputHeight = 500;
    let cropMaxBytes = BANNER_MAX_BYTES;
    let cropRatioLabel = '3.5:1';

    function handleImageFile(input, hiddenId, previewId, options) {
        const file = input.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            openCropModal(e.target.result, options).then(function(croppedBase64) {
                document.getElementById(hiddenId).value = croppedBase64;
                showImagePreview(previewId, croppedBase64);
                if (hiddenId === 'editEventImage') {
                    document.getElementById('removeEditEventImage').style.display = 'inline-flex';
                }
            }).catch(function() {
                input.value = '';
            });
        };
        reader.readAsDataURL(file);
    }

    function openCropModal(imageSrc, options) {
        if (options) {
            cropAspectRatio = options.aspectRatio ?? cropAspectRatio;
            cropOutputWidth = options.outputWidth ?? cropOutputWidth;
            cropOutputHeight = options.outputHeight ?? cropOutputHeight;
            cropMaxBytes = options.maxBytes ?? cropMaxBytes;
            cropRatioLabel = options.ratioLabel ?? cropRatioLabel;
        }
        return new Promise(function(resolve, reject) {
            cropResolve = { resolve: resolve, reject: reject };
            const modal = document.getElementById('cropModal');
            const img = document.getElementById('cropImage');
            const title = document.getElementById('cropModalTitle');
            img.src = imageSrc;
            if (title) title.textContent = 'Bild zuschneiden (' + cropRatioLabel + ')';
            modal.style.display = 'flex';

            if (cropper) { cropper.destroy(); cropper = null; }

            setTimeout(function() {
                cropper = new Cropper(img, {
                    aspectRatio: cropAspectRatio,
                    viewMode: 1,
                    autoCropArea: 1,
                    responsive: true,
                    background: false,
                });
            }, 100);
        });
    }

    function confirmCrop() {
        if (!cropper) return;
        const canvas = cropper.getCroppedCanvas({
            width: cropOutputWidth,
            height: cropOutputHeight,
            imageSmoothingQuality: 'high',
        });
        const base64 = compressToMaxBytes(canvas, cropMaxBytes);
        destroyCropModal();
        if (cropResolve) {
            cropResolve.resolve(base64);
            cropResolve = null;
        }
    }

    function cancelCrop() {
        const resolver = cropResolve;
        cropResolve = null;
        destroyCropModal();
        if (resolver) resolver.reject();
    }

    function destroyCropModal() {
        const modal = document.getElementById('cropModal');
        modal.style.display = 'none';
        if (cropper) { cropper.destroy(); cropper = null; }
        document.getElementById('cropImage').src = '';
    }

    function closeCropModal() {
        cancelCrop();
    }

    function compressToMaxBytes(canvas, maxBytes) {
        let quality = 0.9;
        let dataUrl = canvas.toDataURL('image/jpeg', quality);
        let base64 = dataUrl.split(',')[1];
        while (base64.length * 3 / 4 > maxBytes && quality > 0.3) {
            quality -= 0.1;
            dataUrl = canvas.toDataURL('image/jpeg', quality);
            base64 = dataUrl.split(',')[1];
        }
        return base64;
    }

    function showImagePreview(previewId, base64) {
        const el = document.getElementById(previewId);
        el.innerHTML = '<img src="data:image/jpeg;base64,' + base64 + '" style="max-width:100%;border-radius:8px;aspect-ratio:3.5/1;object-fit:cover;">';
    }

    function clearImagePreview(hiddenId, previewId) {
        document.getElementById(hiddenId).value = '';
        document.getElementById(previewId).innerHTML = '';
        const removeBtn = document.getElementById('removeEditEventImage');
        if (removeBtn) removeBtn.style.display = 'none';
    }

    function removeEventImage(hiddenId, previewId) {
        clearImagePreview(hiddenId, previewId);
        document.getElementById(hiddenId).value = 'null';
    }

    // CRUD: Trips
    async function submitCreateTrip(event) {
        event.preventDefault();
        const allDay = document.getElementById('newTripAllDay').checked;
        const timezone = document.getElementById('newTripTimezone').value;
        const timing = buildTimingPayload(allDay, timezone, 'newTrip');
        if (timing.error) { showToast(timing.error, 'error'); return; }
        const data = Object.assign({
            name: document.getElementById('newTripName').value.trim(),
            description: document.getElementById('newTripDescription').value.trim() || null,
            hastickets: document.getElementById('newTripHastickets').checked ? '1' : '0',
            ticket: document.getElementById('newTripTicket').value.trim() || null,
            ticketUrl: document.getElementById('newTripTicketUrl').value.trim() || null,
        }, timing.data);
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/trips', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Reise erstellt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Erstellen', 'error'); }
    }

    function editEvent(id) {
        const d = eventsData[id];
        if (!d) return;
        document.getElementById('editEventId').value = d.id;
        document.getElementById('editEventName').value = d.name;
        document.getElementById('editEventDescription').value = d.description;
        document.getElementById('editEventTrip').value = d.trip || '';
        document.getElementById('editEventAllDay').checked = d.allDay;
        document.getElementById('editEventTimezone').value = d.timezone;
        document.getElementById('editEventStartDate').value = d.startDate || '';
        document.getElementById('editEventEndDate').value = d.endDate || '';
        document.getElementById('editEventStartAt').value = d.startAtLocal || '';
        document.getElementById('editEventEndAt').value = d.endAtLocal || '';
        document.getElementById('editEventTimeFields').style.display = d.allDay ? 'none' : 'block';
        document.getElementById('editEventDayFields').style.display = d.allDay ? 'block' : 'none';
        const hasTickets = d.hastickets === '1';
        document.getElementById('editEventHastickets').checked = hasTickets;
        document.getElementById('editEventTicketFields').style.display = hasTickets ? 'block' : 'none';
        document.getElementById('editEventTicket').value = d.ticket || '';
        document.getElementById('editEventTicketUrl').value = d.ticketUrl || '';
        document.getElementById('editEventUrl').value = d.url || '';
        document.getElementById('editEventOrganizer').value = d.organizer || '';
        document.getElementById('editEventAddress').value = d.address || '';
        document.getElementById('editEventLatitude').value = d.latitude || '';
        document.getElementById('editEventLongitude').value = d.longitude || '';
        document.getElementById('editEventOSMID').value = d.OSMID || '';
        document.getElementById('editEventCitySlug').value = d.citySlug || '';
        clearImagePreview('editEventImage', 'editEventImagePreview');
        if (isValidImageData(d.image)) {
            document.getElementById('editEventImage').value = d.image;
            showImagePreview('editEventImagePreview', d.image);
            document.getElementById('removeEditEventImage').style.display = 'inline-flex';
        }
        showEditEventForm();
    }

    function isValidImageData(value) {
        if (typeof value !== 'string' || value === '') return false;
        if (!/^[A-Za-z0-9+\/=]+$/.test(value)) return false;
        return value.startsWith('/9j/') || value.startsWith('iVBOR') || value.startsWith('UklGR');
    }

    function editTrip(id) {
        const d = tripsData[id];
        if (!d) return;
        document.getElementById('editTripId').value = d.id;
        document.getElementById('editTripName').value = d.name;
        document.getElementById('editTripDescription').value = d.description;
        document.getElementById('editTripAllDay').checked = d.allDay;
        document.getElementById('editTripTimezone').value = d.timezone;
        document.getElementById('editTripStartDate').value = d.startDate || '';
        document.getElementById('editTripEndDate').value = d.endDate || '';
        document.getElementById('editTripStartAt').value = d.startAtLocal || '';
        document.getElementById('editTripEndAt').value = d.endAtLocal || '';
        document.getElementById('editTripTimeFields').style.display = d.allDay ? 'none' : 'block';
        document.getElementById('editTripDayFields').style.display = d.allDay ? 'block' : 'none';
        const hasTickets = d.hastickets === '1';
        document.getElementById('editTripHastickets').checked = hasTickets;
        document.getElementById('editTripTicketFields').style.display = hasTickets ? 'block' : 'none';
        document.getElementById('editTripTicket').value = d.ticket || '';
        document.getElementById('editTripTicketUrl').value = d.ticketUrl || '';
        showEditTripForm();
    }

    async function submitEditTrip(event) {
        event.preventDefault();
        const id = document.getElementById('editTripId').value;
        const allDay = document.getElementById('editTripAllDay').checked;
        const timezone = document.getElementById('editTripTimezone').value;
        const timing = buildTimingPayload(allDay, timezone, 'editTrip');
        if (timing.error) { showToast(timing.error, 'error'); return; }
        const data = Object.assign({
            name: document.getElementById('editTripName').value.trim(),
            description: document.getElementById('editTripDescription').value.trim() || null,
            hastickets: document.getElementById('editTripHastickets').checked ? '1' : '0',
            ticket: document.getElementById('editTripTicket').value.trim() || null,
            ticketUrl: document.getElementById('editTripTicketUrl').value.trim() || null,
        }, timing.data);
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Reise aktualisiert'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Speichern', 'error'); }
    }

    async function deleteTrip(id, name) {
        if (!confirm('Reise "' + name + '" wirklich löschen?\n\nAlle zugehörigen Events, Unterkünfte und Relationen werden ebenfalls gelöscht.')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Reise gelöscht'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Löschen', 'error'); }
    }

    // CRUD: Events
    async function submitCreateEvent(event) {
        event.preventDefault();
        const imageData = document.getElementById('newEventImage').value;
        const allDay = document.getElementById('newEventAllDay').checked;
        const timezone = document.getElementById('newEventTimezone').value;
        const timing = buildTimingPayload(allDay, timezone, 'newEvent');
        if (timing.error) { showToast(timing.error, 'error'); return; }
        const data = Object.assign({
            name: document.getElementById('newEventName').value.trim(),
            description: document.getElementById('newEventDescription').value.trim() || null,
            trip: document.getElementById('newEventTrip').value || null,
            hastickets: document.getElementById('newEventHastickets').checked ? '1' : '0',
            ticket: document.getElementById('newEventTicket').value.trim() || null,
            ticketUrl: document.getElementById('newEventTicketUrl').value.trim() || null,
            url: document.getElementById('newEventUrl').value.trim() || null,
            image: imageData || null,
            organizer: document.getElementById('newEventOrganizer').value.trim() || null,
            address: document.getElementById('newEventAddress').value.trim() || null,
            latitude: document.getElementById('newEventLatitude').value.trim() || null,
            longitude: document.getElementById('newEventLongitude').value.trim() || null,
            OSMID: document.getElementById('newEventOSMID').value.trim() || null,
            citySlug: document.getElementById('newEventCitySlug').value.trim() || null,
        }, timing.data);
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/events', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event erstellt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Erstellen', 'error'); }
    }

    async function submitEditEvent(event) {
        event.preventDefault();
        const id = document.getElementById('editEventId').value;
        const imageData = document.getElementById('editEventImage').value;
        const allDay = document.getElementById('editEventAllDay').checked;
        const timezone = document.getElementById('editEventTimezone').value;
        const timing = buildTimingPayload(allDay, timezone, 'editEvent');
        if (timing.error) { showToast(timing.error, 'error'); return; }
        const data = Object.assign({
            name: document.getElementById('editEventName').value.trim(),
            description: document.getElementById('editEventDescription').value.trim() || null,
            trip: document.getElementById('editEventTrip').value || null,
            hastickets: document.getElementById('editEventHastickets').checked ? '1' : '0',
            ticket: document.getElementById('editEventTicket').value.trim() || null,
            ticketUrl: document.getElementById('editEventTicketUrl').value.trim() || null,
            url: document.getElementById('editEventUrl').value.trim() || null,
            image: imageData === 'null' ? null : (imageData || undefined),
            organizer: document.getElementById('editEventOrganizer').value.trim() || null,
            address: document.getElementById('editEventAddress').value.trim() || null,
            latitude: document.getElementById('editEventLatitude').value.trim() || null,
            longitude: document.getElementById('editEventLongitude').value.trim() || null,
            OSMID: document.getElementById('editEventOSMID').value.trim() || null,
            citySlug: document.getElementById('editEventCitySlug').value.trim() || null,
        }, timing.data);
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/events/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event aktualisiert'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Speichern', 'error'); }
    }

    async function deleteEvent(id, name) {
        if (!confirm('Event "' + name + '" wirklich löschen?\n\nAlle zugehörigen Ticket- und Teilnehmerdaten werden ebenfalls gelöscht.')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/events/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event gelöscht'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Löschen', 'error'); }
    }
</script>
