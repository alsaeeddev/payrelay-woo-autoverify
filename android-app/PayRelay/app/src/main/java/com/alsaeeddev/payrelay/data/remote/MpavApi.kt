package com.alsaeeddev.payrelay.data.remote

import com.alsaeeddev.payrelay.data.remote.dto.PairResponse
import com.alsaeeddev.payrelay.data.remote.dto.SmsAckResponse
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Url

interface MpavApi {

    @POST
    suspend fun pair(
        @Url url: String,
        @Body body: RequestBody
    ): Response<PairResponse>

    @POST
    suspend fun sendSms(
        @Url url: String,
        @Header(PreparedSignedRequest.HEADER_DEVICE) deviceId: String,
        @Header(PreparedSignedRequest.HEADER_TIMESTAMP) timestamp: String,
        @Header(PreparedSignedRequest.HEADER_NONCE) nonce: String,
        @Header(PreparedSignedRequest.HEADER_SIGNATURE) signature: String,
        @Body body: RequestBody
    ): Response<SmsAckResponse>

    @POST
    suspend fun sendHeartbeat(
        @Url url: String,
        @Header(PreparedSignedRequest.HEADER_DEVICE) deviceId: String,
        @Header(PreparedSignedRequest.HEADER_TIMESTAMP) timestamp: String,
        @Header(PreparedSignedRequest.HEADER_NONCE) nonce: String,
        @Header(PreparedSignedRequest.HEADER_SIGNATURE) signature: String,
        @Body body: RequestBody
    ): Response<ResponseBody>
}
