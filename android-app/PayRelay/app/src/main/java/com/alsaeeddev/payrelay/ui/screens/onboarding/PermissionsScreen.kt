package com.alsaeeddev.payrelay.ui.screens.onboarding

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Sms
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.alsaeeddev.payrelay.R

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PermissionsScreen(
    onContinueToPair: () -> Unit,
    onBack: () -> Unit
) {
    val context = LocalContext.current

    fun checkGranted(permission: String): Boolean =
        ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED

    var hasReceiveSms by remember { mutableStateOf(checkGranted(Manifest.permission.RECEIVE_SMS)) }
    var hasNotifications by remember {
        mutableStateOf(
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                checkGranted(Manifest.permission.POST_NOTIFICATIONS)
            } else {
                true
            }
        )
    }
    var deniedOnce by remember { mutableStateOf(false) }

    val launcher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions()
    ) { results ->
        hasReceiveSms = results[Manifest.permission.RECEIVE_SMS] ?: checkGranted(Manifest.permission.RECEIVE_SMS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            hasNotifications = results[Manifest.permission.POST_NOTIFICATIONS]
                ?: checkGranted(Manifest.permission.POST_NOTIFICATIONS)
        }
        if (!hasReceiveSms) {
            deniedOnce = true
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.permissions_title)) },
                navigationIcon = {
                    IconButton(
                        onClick = onBack,
                        modifier = Modifier.testTag("permissions_back_button")
                    ) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = stringResource(R.string.action_back)
                        )
                    }
                }
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = 600.dp)
                    .fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                Text(
                    text = stringResource(R.string.permissions_intro),
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )

                PermissionStatusCard(
                    icon = Icons.Default.Sms,
                    title = stringResource(R.string.permissions_sms_title),
                    description = stringResource(R.string.permissions_sms_desc),
                    granted = hasReceiveSms
                )

                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    PermissionStatusCard(
                        icon = Icons.Default.Notifications,
                        title = stringResource(R.string.permissions_notif_title),
                        description = stringResource(R.string.permissions_notif_desc),
                        granted = hasNotifications
                    )
                }

                if (deniedOnce && !hasReceiveSms) {
                    Card(
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.errorContainer
                        )
                    ) {
                        Column(
                            modifier = Modifier.padding(16.dp),
                            verticalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Text(
                                text = stringResource(R.string.permissions_denied_help),
                                color = MaterialTheme.colorScheme.onErrorContainer,
                                style = MaterialTheme.typography.bodyMedium
                            )
                            OutlinedButton(
                                onClick = {
                                    val intent = Intent(
                                        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                        Uri.fromParts("package", context.packageName, null)
                                    )
                                    context.startActivity(intent)
                                },
                                modifier = Modifier
                                    .heightIn(min = 48.dp)
                                    .testTag("open_app_settings_button")
                            ) {
                                Text(stringResource(R.string.permissions_open_settings))
                            }
                        }
                    }
                }

                Spacer(Modifier.padding(top = 4.dp))

                if (!hasReceiveSms || !hasNotifications) {
                    OutlinedButton(
                        onClick = {
                            val toRequest = buildList {
                                if (!hasReceiveSms) add(Manifest.permission.RECEIVE_SMS)
                                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && !hasNotifications) {
                                    add(Manifest.permission.POST_NOTIFICATIONS)
                                }
                            }.toTypedArray()
                            if (toRequest.isNotEmpty()) {
                                launcher.launch(toRequest)
                            }
                        },
                        modifier = Modifier
                            .fillMaxWidth()
                            .heightIn(min = 48.dp)
                            .testTag("request_permissions_button")
                    ) {
                        Text(stringResource(R.string.permissions_grant_button))
                    }
                }

                Button(
                    onClick = onContinueToPair,
                    enabled = hasReceiveSms,
                    modifier = Modifier
                        .fillMaxWidth()
                        .heightIn(min = 52.dp)
                        .testTag("permissions_continue_button")
                ) {
                    Text(stringResource(R.string.permissions_continue_button))
                }
            }
        }
    }
}

@Composable
private fun PermissionStatusCard(
    icon: ImageVector,
    title: String,
    description: String,
    granted: Boolean
) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceContainerLow
        )
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Icon(
                imageVector = if (granted) Icons.Default.CheckCircle else icon,
                contentDescription = null,
                tint = if (granted) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant
            )
            Column(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                Text(text = title, style = MaterialTheme.typography.titleMedium)
                Text(
                    text = description,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            Text(
                text = if (granted) stringResource(R.string.status_granted) else stringResource(R.string.status_required),
                style = MaterialTheme.typography.labelMedium,
                color = if (granted) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error
            )
        }
    }
}
