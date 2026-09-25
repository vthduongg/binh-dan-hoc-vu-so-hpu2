import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '1m', target: 50 },
    { duration: '3m', target: 200 },
    { duration: '2m', target: 200 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500'],
  },
};

const base = __ENV.BASE_URL || 'http://localhost/hpu2-xampp';

export default function () {
  const home = http.get(`${base}/index.php`);
  check(home, { 'trang chủ 200': (r) => r.status === 200 });
  const paths = http.get(`${base}/index.php?page=paths`);
  check(paths, { 'lộ trình 200': (r) => r.status === 200 });
  sleep(Math.random() * 2 + 1);
}
