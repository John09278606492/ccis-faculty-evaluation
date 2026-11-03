// Read interfaces each time: DHCP can change a campus address while the server runs.
function campusLinks(interfaces, port) {
    return Object.entries(interfaces).flatMap(([name, addresses]) =>
        (addresses || []).filter(address => (address.family === 'IPv4' || address.family === 4)
            && !address.internal && !address.address.startsWith('169.254.'))
            .map(address => ({ name, url: `http://${address.address}:${port}` })))
        .sort((a, b) => Number(/wi-fi|wireless/i.test(b.name)) - Number(/wi-fi|wireless/i.test(a.name))
            || a.name.localeCompare(b.name) || a.url.localeCompare(b.url));
}

module.exports = { campusLinks };
