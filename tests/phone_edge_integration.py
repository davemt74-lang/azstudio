#!/usr/bin/env python3
"""Focused HTTP regressions for approval-only relay credentials and offer retry safety."""
import argparse
import subprocess
import unittest
from phone_integration import PhoneIntegration


class PhoneEdgeIntegration(PhoneIntegration):
    def test_edge_pending_phone_cannot_read_private_relay_credentials(self):
        config = self.root / 'storage/config.php'
        original = config.read_bytes()
        try:
            subprocess.run([self.php, '-r', "$f=$argv[1]; $c=require $f; $c['phone_ice_servers']=[['urls'=>'turn:relay.example.invalid:3478','username'=>'test-relay-user','credential'=>'test-relay-secret']]; file_put_contents($f, '<?php return '.var_export($c,true).';');", str(config)], check=True, capture_output=True)
            interview, phone = self.paired(approved=False)
            status, result = self.request_phone('context', {'interviewId': interview['id'], 'questionId': 'casting-private', 'questionText': 'Private casting session question', 'isPrivate': True}, host=True)
            self.assertEqual(status, 200, result)
            status, pending = self.request_phone('status', phone=phone)
            self.assertEqual(status, 200, pending)
            self.assertEqual(pending['data']['iceServers'], [])
            self.assertIsNone(pending['data']['question'])
            self.assertNotIn('Private casting session question', str(pending))
            self.assertNotIn('test-relay-secret', str(pending))
            status, result = self.request_phone('approve', {'interviewId': interview['id'], 'id': phone['id']}, host=True)
            self.assertEqual(status, 200, result)
            status, approved = self.request_phone('status', phone=phone)
            self.assertEqual(status, 200, approved)
            self.assertEqual(approved['data']['iceServers'][0]['credential'], 'test-relay-secret')
            self.assertEqual(approved['data']['question']['text'], 'Private casting session question')
            self.assertTrue(approved['data']['question']['private'])
        finally:
            config.write_bytes(original)

    def test_edge_duplicate_offer_preserves_answer_and_conflicting_nonce_is_rejected(self):
        interview, phone = self.paired()
        nonce = '7' * 32
        offer = 'v=0\r\ns=AZHottie offer retry test\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\n'
        answer = offer + 'a=recvonly\r\n'
        status, result = self.request_phone('liveOffer', {'nonce': nonce, 'sdp': offer}, phone=phone)
        self.assertEqual(status, 201, result)
        status, result = self.request_phone('answer', {'interviewId': interview['id'], 'id': phone['id'], 'nonce': nonce, 'sdp': answer}, host=True)
        self.assertEqual(status, 200, result)
        status, result = self.request_phone('liveOffer', {'nonce': nonce, 'sdp': offer}, phone=phone)
        self.assertEqual(status, 200, result)
        status, result = self.request_phone('livePoll', phone=phone, query={'nonce': nonce})
        self.assertEqual(status, 200, result)
        self.assertEqual(result['data']['answer'], answer)
        status, result = self.request_phone('liveOffer', {'nonce': nonce, 'sdp': offer + 'a=sendonly\r\n'}, phone=phone)
        self.assertEqual(status, 409, result)
        self.assertEqual(result['error']['code'], 'live_conflict')
        status, result = self.request_phone('livePoll', phone=phone, query={'nonce': nonce})
        self.assertEqual(result['data']['answer'], answer)
        status, result = self.request_phone('liveOffer', {'nonce': '8' * 32, 'sdp': offer}, phone=phone)
        self.assertEqual(status, 201, result)
        status, result = self.request_phone('livePoll', phone=phone, query={'nonce': '8' * 32})
        self.assertIsNone(result['data']['answer'])


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    PhoneEdgeIntegration.php = args.php
    suite = unittest.TestSuite(PhoneEdgeIntegration(name) for name in unittest.defaultTestLoader.getTestCaseNames(PhoneEdgeIntegration) if name.startswith('test_edge_'))
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(0 if result.wasSuccessful() else 1)
