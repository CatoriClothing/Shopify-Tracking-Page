# Shopify-Tracking-Page
Basic functionality shouldn't cost money. 

## About
Track USPS packages and show estimated delivery dates, latest scan events, package class and any other data that USPS provides via their API.
Can be worked to use any shipping carriers API. Swiss Post and Asendia have strict limitations and don't provide a public API.

## Overview
- Create a new page using the page template and style it as required. 
- Update the API keys and URL's on the tracking script and upload to your server
- Point ajax request to script and away you go.

## LIMITATIONS
- Only USPS is supported. If the tracking number is a DHL or Globgistcs tracking number it will provide the approriate links to redirect the customer.
- No historical data or tracking. Only current status, estimated delivery date and 1 previous scan event will show. 
- Rate Limiting only functions for direct access not ajax requests. You will need to add your own rate limiting implementation

