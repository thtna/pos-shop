// CẦU NỐI IN (Print Bridge) cho POS Shop — máy in nhiệt LAN cổng 9100
// Cách dùng:
//   1. Cài Node.js trên máy thu ngân (https://nodejs.org)
//   2. Chạy:  node print-bridge.js
//   3. Trong POS Shop → 🖨️ → Kiểu "LAN" → nhập: http://localhost:9100/print
//   4. Đổi IP máy in trong biến PRINTER_IP bên dưới (IP của máy in trong mạng LAN)
const http = require('http');
const net = require('net');

const PRINTER_IP = '192.168.1.100'; // ← đổi thành IP máy in K80 của bạn

http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', '*');
  if (req.method === 'OPTIONS') return res.end();

  let body = [];
  req.on('data', c => body.push(c));
  req.on('end', () => {
    const data = Buffer.concat(body);
    const host = req.headers['x-printer-ip'] || PRINTER_IP;
    const sock = net.connect(9100, host, () => sock.end(data));
    sock.on('error', e => {
      console.error('Lỗi in tới', host, ':', e.message);
      res.statusCode = 500;
      res.end('ERR: ' + e.message);
    });
    sock.on('close', () => { console.log('Đã in', data.length, 'bytes tới', host); res.end('OK'); });
  });
}).listen(9100, () => console.log('🖨️  Cầu nối in đang chạy tại http://localhost:9100 (máy in:', PRINTER_IP + ')'));
