let map, marker, accuracyCircle;
let lastLoggedAddr = "";

const categoryImages = {
    hospital: "https://images.unsplash.com/photo-1586773860418-d319a39855df?w=400&q=80",
    police: "https://images.unsplash.com/photo-1594470117722-14329ef56b10?w=400&q=80",
    school: "https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=400&q=80",
    college: "https://images.unsplash.com/photo-1541339907198-e08756ebafe1?w=400&q=80",
    restaurant: "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=80",
    cafe: "https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=400&q=80",
    mall: "https://images.unsplash.com/photo-1567449303078-57ad995bd301?w=400&q=80",
    hotel: "https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=80",
    default: "https://images.unsplash.com/photo-1449156003053-c3020626388b?w=400&q=80"
};

function initMap(lat, lng) {
    if (map) return;
    map = L.map('map', { zoomControl: false }).setView([lat, lng], 17);

    L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
    }).addTo(map);

    const icon = L.divIcon({
        html: '<div style="text-align:center"><div class="here-label">YOU ARE HERE</div><div style="background:var(--primary); width:14px; height:14px; border-radius:50%; border:3px solid white; margin:auto; box-shadow: 0 0 10px rgba(0,0,0,0.5)"></div></div>',
        className: 'custom-marker', iconSize: [80, 40], iconAnchor: [40, 35]
    });

    marker = L.marker([lat, lng], { icon: icon }).addTo(map);
    accuracyCircle = L.circle([lat, lng], { radius: 20, color: '#2563eb', fillOpacity: 0.1, weight: 1 }).addTo(map);
    setTimeout(() => { map.invalidateSize(); }, 500);
}

async function updateRadar(lat, lng) {
    try {
        const query = `[out:json];node(around:10000, ${lat}, ${lng})["amenity"~"police|hospital|school|cafe|restaurant|mall|hotel|college"];out 10;`;
        const response = await fetch(`https://overpass-api.de/api/interpreter?data=${encodeURIComponent(query)}`);
        const data = await response.json();

        let html = "";
        data.elements.forEach(el => {
            const name = el.tags.name || "Known Point";
            const type = el.tags.amenity || "default";
            const img = categoryImages[type] || categoryImages.default;
            const iconColor = type === 'police' ? 'color: #1e40af;' : 'color: #2563eb;';

            html += `
                <div class="addr-card" onclick="window.open('https://www.google.com/maps/search/${encodeURIComponent(name)}')">
                    <img src="${img}" class="place-img" alt="place" onerror="this.src='${categoryImages.default}'">
                    <div class="addr-body">
                        <div class="addr-icon"><i class="fas fa-map-pin" style="${iconColor}"></i></div>
                        <div class="addr-info">
                            <h4>${name}</h4>
                            <p>${type.toUpperCase()} • Near You</p>
                        </div>
                    </div>
                </div>`;
        });
        document.getElementById('nearGrid').innerHTML = html || "No landmarks in 10KM range.";
    } catch (e) { console.log("Radar Fail"); }
}

async function getAddr(lat, lng) {
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
        const data = await res.json();
        return data.display_name;
    } catch (e) { return "Finding address..."; }
}

function initiateSystem() {
    if (!navigator.geolocation) return alert("GPS not supported");
    document.getElementById('liveStatus').innerHTML = '<i class="fas fa-circle" style="color:var(--success)"></i> SENSORS ACTIVE';
    document.getElementById('emergencyGrid').style.display = 'grid';
    document.getElementById('mainBtn').innerHTML = '<i class="fas fa-sync fa-spin"></i> RADAR SCANNING...';

    navigator.geolocation.getCurrentPosition(pos => {
        const { latitude, longitude } = pos.coords;
        initMap(latitude, longitude);
        updateRadar(latitude, longitude);
        startWatch();
    }, err => alert("GPS Error: " + err.message), { enableHighAccuracy: true });
}

function startWatch() {
    navigator.geolocation.watchPosition(async pos => {
        const { latitude, longitude, speed, altitude, accuracy, heading } = pos.coords;

        document.getElementById('liveSpeed').innerHTML = `${speed ? (speed * 3.6).toFixed(1) : 0} <small>km/h</small>`;
        document.getElementById('liveAlt').innerHTML = `${altitude ? altitude.toFixed(0) : 0} <small>m</small>`;
        document.getElementById('liveAcc').innerHTML = `${accuracy.toFixed(0)} <small>m</small>`;
        document.getElementById('liveHeading').innerText = heading ? heading.toFixed(0) + "°" : "Fixed";

        if (map) {
            marker.setLatLng([latitude, longitude]);
            accuracyCircle.setLatLng([latitude, longitude]).setRadius(accuracy);
            map.panTo([latitude, longitude]);

            const address = await getAddr(latitude, longitude);
            if (address && address !== lastLoggedAddr) {
                lastLoggedAddr = address;
                const time = new Date().toLocaleTimeString();
                const logBox = document.getElementById('movementLog');
                if(logBox.innerText.includes("Timeline starts")) logBox.innerHTML = "";
                logBox.innerHTML = `<div style="font-size:12px; margin-bottom:8px; border-bottom:1px solid #eee; padding-bottom:5px;"><b>${time}</b>: ${address}</div>` + logBox.innerHTML;
            }

            const gMapsLink = `https://www.google.com/maps?q=${latitude},${longitude}`;
            document.getElementById('whatsappBtn').href = `https://wa.me/?text=${encodeURIComponent("My Live Location: " + address + " " + gMapsLink)}`;
        }

        if (!window.lastRadarUpdate || Date.now() - window.lastRadarUpdate > 60000) {
            updateRadar(latitude, longitude);
            window.lastRadarUpdate = Date.now();
        }
    }, null, { enableHighAccuracy: true });
}
