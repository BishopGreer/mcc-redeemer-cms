-- Migration 0013: Privacy Policy page

INSERT IGNORE INTO pages (title, slug, content, excerpt, status, show_in_nav, meta_title, meta_desc, author_id)
SELECT
  'Privacy Policy',
  'privacy-policy',
  '<h2>Privacy Policy</h2>
<p><em>Effective date: January 1, 2025</em></p>
<p>Your Parish is committed to protecting the privacy of those who visit our website and participate in our parish community. This Privacy Policy explains what information we collect, how we use it, and your rights regarding that information.</p>

<h3>1. Information We Collect</h3>
<p>We collect information in the following ways:</p>
<ul>
  <li><strong>Parish Registration:</strong> When you register with the parish, we collect your name, address, phone number, email address, household information, and sacramental history to maintain our parish records.</li>
  <li><strong>Contact Form:</strong> When you contact us, we collect your name, email address, phone number, and the content of your message.</li>
  <li><strong>Prayer Requests:</strong> When you submit a prayer request, we collect your name and the details of your request.</li>
  <li><strong>Newsletter Subscription:</strong> When you subscribe to our newsletter, we collect your email address.</li>
  <li><strong>Analytics:</strong> Our website may collect anonymous data about how visitors use the site, such as pages visited and time spent on the site. This data does not identify individual visitors.</li>
  <li><strong>Cookies:</strong> Our website uses session cookies to maintain your login state if you are a registered user. We do not use tracking or advertising cookies.</li>
</ul>

<h3>2. How We Use Your Information</h3>
<p>We use the information we collect to:</p>
<ul>
  <li>Maintain accurate parish records and sacramental registers as required by canon law and diocesan policy</li>
  <li>Respond to your inquiries and prayer requests</li>
  <li>Send you parish newsletters and announcements if you have subscribed</li>
  <li>Improve the content and function of our website</li>
  <li>Comply with legal and canonical obligations</li>
</ul>

<h3>3. Social Media</h3>
<p>Our parish shares news and blog posts on social media platforms including Facebook, BlueSky, Threads, and Mastodon. Content shared publicly through these platforms is subject to the privacy policies of those respective platforms. We do not share your personal information with any social media platform.</p>

<h3>4. How We Share Your Information</h3>
<p>We do not sell, trade, or rent your personal information. We may share your information in the following limited circumstances:</p>
<ul>
  <li><strong>Diocesan reporting:</strong> We may share sacramental records with the Diocese as required by canon law.</li>
  <li><strong>Service providers:</strong> We may share information with trusted service providers who assist us in operating our website, under strict confidentiality agreements.</li>
  <li><strong>Legal obligations:</strong> We may disclose your information if required to do so by law or in response to valid legal process.</li>
</ul>

<h3>5. Data Retention</h3>
<p>Sacramental and parish register records are maintained permanently in accordance with canon law and diocesan policy. Contact form submissions, prayer requests, and newsletter subscriber records are retained for as long as necessary to fulfill their purpose and then securely deleted.</p>

<h3>6. Your Rights</h3>
<p>You have the right to:</p>
<ul>
  <li>Request access to the personal information we hold about you</li>
  <li>Request correction of inaccurate information</li>
  <li>Unsubscribe from our newsletter at any time using the link in any newsletter email</li>
  <li>Request deletion of your contact or registration data (note: sacramental records may be subject to canonical retention requirements)</li>
</ul>
<p>To exercise any of these rights, please contact our parish office.</p>

<h3>7. Children''s Privacy</h3>
<p>Our website is not directed to children under the age of 13. We do not knowingly collect personal information from children under 13 without parental consent. If you believe we have inadvertently collected such information, please contact us so we may promptly delete it.</p>

<h3>8. Security</h3>
<p>We take reasonable technical and organizational measures to protect your personal information against unauthorized access, loss, or misuse. However, no transmission of data over the internet can be guaranteed to be completely secure.</p>

<h3>9. Third-Party Links</h3>
<p>Our website may contain links to third-party websites. We are not responsible for the privacy practices of those websites and encourage you to review their privacy policies.</p>

<h3>10. Changes to This Policy</h3>
<p>We may update this Privacy Policy from time to time. When we do, we will revise the effective date at the top of this page. We encourage you to review this policy periodically.</p>

<h3>11. Contact Us</h3>
<p>If you have questions about this Privacy Policy or wish to exercise your rights, please contact us through our <a href="/contact">contact form</a> or by calling the parish office.</p>',
  'Our privacy policy - how we collect, use, and protect your information.',
  'published',
  0,
  'Privacy Policy',
  'Learn how Your Parish collects, uses, and protects your personal information.',
  (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1)
